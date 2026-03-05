# ANÁLISE DE ERROS ANTERIORES E PONTOS DE MELHORIA

## PARTE 1: AUDITORIA DE CÓDIGO

---

### NÍVEL CRÍTICO — Segurança e Vazamento de Dados

---

#### CRÍTICO 1: IDOR em todos os controllers — Policies existem mas nunca são chamadas

**Arquivos:** Todos os controllers (`LancamentoController`, `CategoriaController`, `MetaController`, `OrcamentoController`)

**O que é IDOR:** Insecure Direct Object Reference. O usuário passa um `id` na URL e o sistema não verifica se aquele recurso pertence a ele.

**O problema concreto:**
```php
// LancamentoController.php — método destroy()
public function destroy(Request $request, $id)
{
    $this->lancamentoRepository->delete($id); // ← sem verificação alguma
}

// MetaController.php — método update()
public function update(Request $request, $id)
{
    $this->metaRepository->update($id, $validated); // ← usuário A pode editar meta do usuário B
}

// MetaController.php — adicionarEconomia()
$meta = Meta::findOrFail($validated['meta_id']); // ← sem checar user_id
$meta->valor_atual += $validated['valor'];        // ← qualquer user altera qualquer meta
```

**Ironia:** `LancamentoPolicy`, `CategoriaPolicy` e `MetaPolicy` existem e têm lógica correta — mas NENHUM controller chama `$this->authorize()`. São arquivos mortos.

**Consequência real:** Usuário A pode deletar o banco financeiro inteiro do usuário B trocando o `id` na URL. Isso é **exploração trivial** — qualquer testador com Postman consegue em 2 minutos.

**Como deveria ser:**
```php
// Usando Route Model Binding + Policy:
public function update(UpdateLancamentoRequest $request, Lancamento $lancamento)
{
    $this->authorize('update', $lancamento); // Laravel chama LancamentoPolicy::update()
    $this->lancamentoRepository->update($lancamento->id, $request->validated());
}
```

**O que estudar:**
- Laravel Policies e Gates (documentação oficial)
- Diferença entre autenticação e autorização
- OWASP A01:2021 — Broken Access Control
- Route Model Binding com implicit model binding

---

#### CRÍTICO 2: MetaController::index() usa Meta::all() — expõe dados de TODOS os usuários

**Arquivo:** `app/Http/Controllers/MetaController.php`, linha 22

```php
public function index(Request $request)
{
    $metas = Meta::all(); // ← retorna TODAS as metas do banco
}
```

**Consequência:** Cada usuário que abre a página de Metas vê os objetivos financeiros de TODOS os outros usuários cadastrados no sistema. Privacy violation completo.

**O que estudar:** Query Scopes no Laravel, Global Scopes, princípio de menor privilégio

---

#### CRÍTICO 3: 8 métodos no LancamentoRepository sem filtro de user_id

**Arquivo:** `app/Repositories/LancamentoRepository.php`

Estes métodos retornam dados de todos os usuários:

| Método | Linha aprox. | Problema |
|--------|-------------|---------|
| `getByPeriodo()` | ~153 | Sem `where('user_id', ...)` |
| `getByCategoria()` | ~160 | Sem `where('user_id', ...)` |
| `getReceitasDespesasPorMes()` | ~167 | Agrega dados de todos os usuários |
| `getTotalPorTipo()` | ~188 | Soma valores de todos os usuários |
| `getDespesasPorCategoria()` | ~195 | Agrupa de todos os usuários |
| `getUltimasTransacoes()` | ~205 | Retorna últimas 10 globais |
| `getAnosDisponiveis()` | ~213 | Anos de todos os usuários |
| `getLancamentosRecorrentes()` | ~223 | Recorrentes de todos os usuários |

**Consequência:** O Dashboard de qualquer usuário pode estar mostrando dados de outros. As somas de receitas e despesas são incorretas — incluem transações de outras contas.

**O que estudar:** Query Scopes locais e globais no Eloquent, princípio de data isolation em sistemas multi-usuário

---

#### CRÍTICO 4: OrcamentoRepository::paginate() sem filtro de usuário

**Arquivo:** `app/Repositories/OrcamentoRepository.php`, método `paginate()`, linhas ~14-26

```php
public function paginate(array $filtros = [], int $perPage = 10): LengthAwarePaginator
{
    return $this->model
        ->with('categoria')
        ->byCategoria($filtros['categoria_id'] ?? null)
        ->byTipo($filtros['tipo'] ?? null)
        // ... sem where('user_id', ...)
}
```

**Consequência:** A página de Orçamentos lista orçamentos de todos os usuários.

---

#### CRÍTICO 5: LancamentoController::create() expõe categorias de todos os usuários

**Arquivo:** `app/Http/Controllers/LancamentoController.php`, linha ~51

```php
public function create(Request $request)
{
    $categorias = Categoria::select('id', 'nome')->get(); // ← TODAS as categorias
}
```

Ao mesmo tempo, o método `index()` do mesmo controller usa corretamente `$this->categoriaRepository->getCategoriasDoUsuario($userId)`. **Inconsistência dentro do mesmo arquivo.**

**Duplo problema:** O usuário vê categorias de outros. Pior: a validação do `StoreLancamentoRequest` valida `categoria_id` com `exists:categorias,id` — sem checar `user_id`. Então o usuário pode salvar um lançamento referenciando a categoria privada de outro usuário.

**O que estudar:** Validação com `Rule::exists()` + closure, Eloquent relationships e ownership validation

---

#### CRÍTICO 6: Teste suite inteira desabilita middleware globalmente

**Arquivo:** `tests/TestCase.php`, linha ~13

```php
protected function setUp(): void
{
    parent::setUp();
    $this->withoutMiddleware(); // ← desabilita auth, authorization, CSRF para TODOS os testes
    $this->seed();
}
```

**Consequência direta:** Os bugs CRÍTICOS 1-5 acima poderiam ser detectados pelos testes — mas não são, porque o middleware que protege as rotas está desabilitado. Os testes dão falsa segurança.

**Evidência concreta:** `LancamentoFeatureTest.php` linha ~201 tem `$this->withMiddleware()` para re-habilitar pontualmente em um teste de autenticação — mas o problema maior (authorization) nunca é testado.

**Anti-pattern:** Desabilitar segurança para facilitar testes é o mesmo que remover o alarme do carro para não ouvir barulho.

**O que estudar:**
- Como testar authorization no Laravel sem desabilitar middleware
- `actingAs()` para autenticar em testes
- Separação: testes de unidade (sem middleware) vs testes de feature (com middleware completo)
- Pest PHP `beforeEach()` para setup limpo

---

#### CRÍTICO 7: MetaPolicy::view() sempre retorna false

**Arquivo:** `app/Policies/MetaPolicy.php`, linhas ~25-29

```php
public function view(User $user, Meta $meta): bool
{
    return false; // ← bloqueia TODOS os usuários de ver qualquer meta
}
```

**Dupla ironia:** As policies não são chamadas nos controllers (CRÍTICO 1), então este bug é invisível hoje. Mas se alguém um dia adicionar `$this->authorize('view', $meta)`, nenhum usuário conseguirá ver suas próprias metas — e vai parecer um bug misterioso.

---

#### CRÍTICO 8: MetaRepository tem métodos copiados de CategoriaRepository com nomes e lógica errados

**Arquivo:** `app/Repositories/MetaRepository.php`

```php
// Método com nome errado — está em MetaRepository mas chama-se getCategoriaDoUsuario
public function getCategoriaDoUsuario($userId)
{
    return $this->model::where(function ($query) use ($userId) {
        $query->where('user_id', $userId)
            ->orWhereNull('user_id'); // Meta não tem user_id null — regra sem sentido
    })->get();
}

// Método que filtra por 'tipo' — Meta não tem coluna 'tipo'!
public function getCategoriaDespesas()
{
    return $this->model
        ->where('tipo', 'despesa') // ← Meta não tem essa coluna
        ->orderBy('nome')
        ->get();
}
```

**Causa:** Copy-paste de `CategoriaRepository` sem adaptar. É código morto com lógica incorreta que silenciosamente retorna resultados errados se chamado.

**O que estudar:** Princípio DRY, quando usar herança vs composição em repositories, code review como prática

---

### NÍVEL ALTO — Problemas de Integridade de Dados e Lógica

---

#### ALTO 1: Constraint UNIQUE de Orçamento não inclui user_id

**Arquivo:** Migration `create_orcamentos_table.php`

```php
$table->unique(['categoria_id', 'tipo', 'ano', 'mes'], 'unique_orcamento');
// ← Falta user_id!
```

**Consequência:** Se Usuário A tem `categoria_id=5` e Usuário B também tem `categoria_id=5` (IDs auto-incrementais são compartilhados), o banco rejeita o orçamento do Usuário B dizendo que "já existe". Em sistemas multi-usuário, constraints únicas quase sempre precisam incluir `user_id`.

**O que estudar:** Composite unique constraints, multi-user database design

---

#### ALTO 2: Validação não garante que fim_da_recorrencia só exista quando há recorrência

**Arquivo:** `app/Models/Lancamento.php`, regras de validação

```php
'intervalo_meses' => 'required|integer|min:0',
'fim_da_recorrencia' => 'nullable|date_format:d/m/Y|after_or_equal:data',
```

**Problemas:**
1. Sem limite máximo em `intervalo_meses` — alguém pode inserir `intervalo_meses=999999`
2. `fim_da_recorrencia` pode ser preenchida mesmo com `intervalo_meses=0` (sem recorrência) — dado incoerente
3. Sem validação `required_if:intervalo_meses,>,0`

**Consequência:** O `DashboardRepository::expandirLancamentosRecorrentes()` pode gerar milhares de entradas em memória para um único lançamento com intervalo absurdo.

**O que estudar:** `required_if`, `prohibited_unless`, regras de validação condicionais no Laravel

---

#### ALTO 3: Race condition na criação de Orçamento

**Arquivo:** `app/Http/Controllers/OrcamentoController.php`

```php
if ($this->orcamentoRepository->existeOrcamento(...)) {
    return back()->withErrors(...);
}
// ← Dois requests simultâneos chegam aqui ao mesmo tempo
$this->orcamentoRepository->create($data); // Ambos criam → viola constraint
```

**Consequência:** Em produção com múltiplas requisições simultâneas, um usuário consegue criar orçamentos duplicados. O banco vai estourar a constraint mas o erro não é tratado graciosamente.

**O que estudar:** `DB::transaction()`, `lockForUpdate()`, `firstOrCreate()`, `updateOrCreate()` no Eloquent, race conditions em aplicações web

---

#### ALTO 4: LancamentoRepository::insert() bulk não valida user_id

**Arquivo:** `app/Repositories/LancamentoRepository.php`, método `insert()`

```php
public function insert(array $data)
{
    return Lancamento::query()->insert($data);
    // ← Não verifica se user_id nos dados é o usuário autenticado
}
```

**Consequência:** Um `ImportController` mal implementado poderia injetar transações com `user_id` de outro usuário no banco. Sem validação na camada de persistência.

---

#### ALTO 5: Sem Soft Deletes em nenhum model

**Todos os models:** Nenhum usa o trait `SoftDeletes`.

**Consequência:** Deletar um lançamento é permanente e irreversível. Em um sistema financeiro pessoal, isso é crítico — um clique errado apaga dados históricos para sempre. Além disso, sem soft deletes, não há trilha de auditoria de o que foi deletado.

**O que estudar:** `SoftDeletes` trait, `withTrashed()`, `restore()`, audit logging, `spatie/laravel-activitylog`

---

### NÍVEL MÉDIO — Anti-patterns e Código de Baixa Qualidade

---

#### MÉDIO 1: Validação inline nos controllers em vez de Form Requests

**Arquivos:** `MetaController.php` e `CategoriaController.php`

```php
// MetaController::store() — $request->validate() inline
$validated = $request->validate([
    'titulo' => 'required|string|max:255',
    'valor_a_alcancar' => 'required|numeric|min:0.01',
    // ...
]);
```

`LancamentoController` tem `StoreLancamentoRequest` e `UpdateLancamentoRequest` corretos. `Meta` e `Categoria` não têm. **Inconsistência no mesmo projeto.**

**Por que é ruim:** Duplicação entre store/update, impossível testar unitariamente a lógica de validação, controller fica gordo.

**O que estudar:** Form Request classes, `authorize()` em Form Requests, validação com Rules customizadas

---

#### MÉDIO 2: ImportController é um controller gordo — viola Single Responsibility

**Arquivo:** `app/Http/Controllers/ImportController.php`

O controller faz tudo: recebe arquivo, valida, chama API Python, filtra resultados, transforma datas, persiste no banco. São pelo menos 4 responsabilidades diferentes.

**Hard-code de regra de negócio:**
```php
foreach ($apiResult as $lancamentoData) {
    if (str_contains($lancamentoData['descricao'], 'BB Rende')) {
        continue; // ← regra de negócio hard-coded no controller
    }
}
```

**O que estudar:** SRP (Single Responsibility Principle), Service Layer pattern, como mover lógica de importação para `ImportService`

---

#### MÉDIO 3: Conversão de data no Controller em vez de Mutator

**Arquivo:** `MetaController.php`, métodos `store()` e `update()`

```php
if ($validated['data_final']) {
    $validated['data_final'] = Carbon::createFromFormat('d/m/Y', $validated['data_final'])->format('Y-m-d');
}
```

O próprio `Lancamento` model já usa mutators (`setDataAttribute()`) para fazer essa conversão automaticamente. `Meta` não usa — a conversão está duplicada no controller.

**O que estudar:** Eloquent Mutators e Accessors, `Attribute` casting no Laravel 9+

---

#### MÉDIO 4: Exception catching morto e exception swallowing

**Arquivo:** `LancamentoController.php`

```php
try {
    $dados = $request->validated(); // validated() já lança ValidationException
} catch (\Illuminate\Validation\ValidationException $e) {
    return back()->withErrors($e->errors()); // ← NUNCA chega aqui
} catch (\Exception $e) {
    // engole o erro real, mostra mensagem genérica, sem log
    return redirect()->route('lancamentos.index')->with('error', 'Tente novamente.');
}
```

**Duplo problema:**
1. O catch de `ValidationException` é código morto — `validated()` já trata isso antes
2. O `catch (\Exception $e)` não loga o erro real — em produção você nunca saberá o que quebrou

**O que estudar:** Hierarquia de exceptions do PHP, `Log::error()` com contexto, exception handling no Laravel (`Handler.php`), custom exceptions

---

#### MÉDIO 5: DashboardService filtra em memória PHP em vez do banco

**Arquivo:** `app/Services/DashboardService.php`, método `getGraficoReceitasDespesas()`

```php
$lancamentos = $this->repository->getLancamentosComRecorrencia($userId, $filters);
// Carrega TODOS os lançamentos do ano em memória

$dadosPorMes = collect(range(1, 12))->map(function ($mes) use ($lancamentos) {
    $lancamentosMes = $lancamentos->where('mes', $mes); // filtro em PHP
    return [
        'receitas' => $lancamentosMes->where('tipo', 'receita')->sum('valor'), // soma em PHP
        'despesas' => $lancamentosMes->where('tipo', 'despesa')->sum('valor'),
    ];
});
```

**Com 10.000 lançamentos:** Carrega 10.000 registros, faz 12 iterações com filtros PHP, soma em PHP. O banco faria isso em uma query com `GROUP BY` em milissegundos.

**O que estudar:** `selectRaw()`, `groupBy()`, `having()` no Eloquent, quando usar agregações no banco vs na aplicação, Laravel Telescope para detectar queries lentas

---

#### MÉDIO 6: Logging de dados sensíveis

**Arquivo:** `app/Services/PythonApiService.php`

```php
Log::info('Resposta da API Python', [
    'body' => $response->body() // ← corpo completo da resposta logado
]);
```

O corpo da resposta contém dados financeiros do usuário. Em produção, logs geralmente são acessíveis por múltiplos membros da equipe.

**O que estudar:** Princípio de data minimization em logs, PII (Personally Identifiable Information) handling, log levels e quando usar cada um

---

#### MÉDIO 7: HandleProprietario trait sem type hints nem null safety

**Arquivo:** `app/Traits/HandleProprietario.php`

```php
protected function isOwner(User $user, $model) // ← $model sem tipo
{
    return $user->id === $model->user_id; // ← sem verificar se user_id é null
}
```

Se `$model->user_id` for null (categorias globais), a comparação `$user->id === null` retorna false silenciosamente — sem erro, sem aviso. Comportamento não intencional.

**O que estudar:** Type declarations no PHP 8+, null safety (`?->`, `??`), strict_types

---

### NÍVEL BAIXO — Qualidade de Código e Configuração

---

#### BAIXO 1: Dados sensíveis do User enviados ao frontend via Inertia

**Arquivo:** `app/Http/Controllers/LancamentoController.php`

```php
return Inertia::render('Lancamentos/Create', [
    'user' => $user, // ← objeto User completo, inclui todos os atributos
]);
```

O objeto `User` completo é serializado em JSON no HTML da página. No DevTools do browser, o usuário consegue ver todos os atributos — potencialmente `email`, timestamps, etc.

**Como corrigir:**
```php
'user' => $user->only(['id', 'name']),
```

**O que estudar:** Inertia.js shared data, API Resources (`JsonResource`) para serialização controlada, princípio de data minimization

---

#### BAIXO 2: Configurações de segurança incorretas no .env.example

```env
APP_DEBUG=true          # nunca true em produção
SESSION_LIFETIME=120    # 2 minutos — usuário é deslogado a cada pausa
SESSION_ENCRYPT=false   # dados de sessão sem criptografia
```

**O que estudar:** Environment-specific configuration, diferença entre `.env` e `.env.example`, 12-factor app principles

---

#### BAIXO 3: routes/api.php completamente vazio

**Arquivo:** `routes/api.php`

```php
Route::middleware(['auth:sanctum'])->group(function () {
    // Rotas para gráficos
});
```

Comentário placeholder sem nenhuma rota. `Sanctum` está instalado mas não utilizado. Código morto que confunde quem lê o projeto.

**O que estudar:** Quando usar API routes vs web routes no Laravel, diferença entre session auth e token auth (Sanctum)

---

#### BAIXO 4: Factory retorna data como string em vez de Carbon

**Arquivo:** `database/factories/LancamentoFactory.php`

```php
'data' => Carbon::instance($this->faker->dateTimeBetween('-1 year', 'now'))->format('d/m/Y'),
// ← retorna string '15/03/2025', não um objeto Carbon
```

Funciona porque o model tem um mutator que converte strings. Mas o contrato de uma Factory deveria ser retornar dados no formato que o model espera nativamente. É um acidente esperando para acontecer se o mutator mudar.

---

#### BAIXO 5: Props Vue não tipadas

**Arquivo:** `resources/js/Pages/Categorias/Index.vue`

```javascript
const props = defineProps({
    categorias: Array, // ← sem estrutura interna definida
    orcamentos: Array,
})
```

Sem tipagem, erros de estrutura de dados só aparecem em runtime. `Dashboard.vue` tem valores padrão definidos — inconsistência no mesmo projeto.

**O que estudar:** TypeScript com Vue 3, `defineProps` com tipos genéricos, validação de props

---

#### BAIXO 6: `loading` ref definido mas nunca usado

**Arquivo:** `resources/js/Pages/Dashboard.vue`

```javascript
const loading = ref(false) // definido mas nunca muda de valor nem é lido
```

Código morto no frontend.

---

#### BAIXO 7: Nomes de índices hardcoded no rollback de migration

**Arquivo:** `database/migrations/2025_09_11_000001_add_indexes_to_lancamentos_table.php`

```php
public function down(): void
{
    $table->dropIndex(['lancamentos_user_id_data_index']); // nome gerado automaticamente
}
```

Laravel gera nomes de índice automaticamente. Se a convenção mudar, o rollback quebra silenciosamente.

---

#### BAIXO 8: Migration usa raw SQL não-portável

**Arquivo:** `database/migrations/2025_12_03_180744_update_status_enum_in_metas.php`

```php
DB::statement("ALTER TABLE metas DROP CONSTRAINT IF EXISTS metas_status_check");
DB::statement("ALTER TABLE metas ADD CONSTRAINT metas_status_check CHECK (status IN (...))");
```

Sintaxe PostgreSQL pura. Não funciona em MySQL. Em testes com SQLite (que o projeto usa), pode silenciosamente não executar.

**O que estudar:** Migrations portáveis com Schema Builder, quando raw SQL é aceitável, testar migrations no mesmo banco do teste

---

### RESUMO: O QUE ESTUDA
| IDOR em controllers | OWASP A01, Laravel Policies | laravel.com/docs/authorization |
| Queries sem user scope | Global Scopes, Local Scopes | laravel.com/docs/eloquent#query-scopes |
| withoutMiddleware() nos testes | Feature Testing com autenticação real | Pest docs, Laracasts Testing Laravel |
| Validação inline | Form Requests, custom Rules | laravel.com/docs/validation |
| Fat controller (ImportController) | SRP, Service Layer | "Laravel Beyond CRUD" — Bram Van Damme |
| Exception swallowing | PHP Exception hierarchy, Log::error() | php.net/exceptions |
| Filtro em PHP em vez do banco | Eloquent aggregations, selectRaw | laravel.com/docs/queries |
| Race conditions | DB::transaction(), lockForUpdate() | laravel.com/docs/queries#pessimistic-locking |
| Dados sensíveis no frontend | API Resources, data minimization | laravel.com/docs/eloquent-resources |
| Sem soft deletes | SoftDeletes trait | laravel.com/docs/eloquent#soft-deleting |
| Configs inseguras | 12-factor app, security headers | 12factor.net |
| Props Vue sem tipos | TypeScript, Vue 3 defineProps | vuejs.org/guide/typescript |R (mapa de aprendizado baseado nos erros)

| Erro encontrado | Tópico a estudar | Recurso |
|---|---|---|
| IDOR em controllers | OWASP A01, Laravel Policies | laravel.com/docs/authorization |
| Queries sem user scope | Global Scopes, Local Scopes | laravel.com/docs/eloquent#query-scopes |
| withoutMiddleware() nos testes | Feature Testing com autenticação real | Pest docs, Laracasts Testing Laravel |
| Validação inline | Form Requests, custom Rules | laravel.com/docs/validation |
| Fat controller (ImportController) | SRP, Service Layer | "Laravel Beyond CRUD" — Bram Van Damme |
| Exception swallowing | PHP Exception hierarchy, Log::error() | php.net/exceptions |
| Filtro em PHP em vez do banco | Eloquent aggregations, selectRaw | laravel.com/docs/queries |
| Race conditions | DB::transaction(), lockForUpdate() | laravel.com/docs/queries#pessimistic-locking |
| Dados sensíveis no frontend | API Resources, data minimization | laravel.com/docs/eloquent-resources |
| Sem soft deletes | SoftDeletes trait | laravel.com/docs/eloquent#soft-deleting |
| Configs inseguras | 12-factor app, security headers | 12factor.net |
| Props Vue sem tipos | TypeScript, Vue 3 defineProps | vuejs.org/guide/typescript |


## PARTE 2: AUDITORIA DE DEVOPS — GitHub Actions, Docker e Nginx

> Análise dos arquivos de infraestrutura. Os erros aqui são tão graves quanto os de código — em produção, eles são o que derruba o sistema.

---

### NÍVEL CRÍTICO — Falhas que quebram produção

---

#### DEVOPS CRÍTICO 1: CI e Build estão completamente desconectados — código vai para produção SEM testes

**Arquivos:** `.github/workflows/laravel-ci.yml` e `.github/workflows/build.yml`

```yaml
# laravel-ci.yml
on:
  workflow_dispatch:  # ← só roda quando alguém aciona manualmente

# build.yml
on:
  push:
    branches: [ main ]  # ← roda automaticamente em todo push
```

**Consequência:** Todo commit na branch `main` dispara o build e o deploy automaticamente. Os testes **nunca rodam automaticamente**. Você pode fazer `git push origin main` com código quebrado e ele vai para produção sem qualquer barreira.

**O fluxo correto seria:**
```
push → testes (ci.yml) → se passou → build → se passou → deploy
```

**O que estudar:** GitHub Actions workflow dependencies (`needs:`), branch protection rules, required status checks, Git Flow vs trunk-based development

---

#### DEVOPS CRÍTICO 2: Os dois entrypoints rodam `migrate --force` — race condition em escala

**Arquivos:** `docker/development/php-fpm/entrypoint.sh` e `docker/production/php-fpm/entrypoint.sh`

```bash
#!/bin/sh
set -e
php artisan migrate --force   # ← roda toda vez que o container sobe
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize:clear    # ← apaga o cache que acabou de criar!
exec "$@"
```

**Problema 1 — Race condition:** Se você escalar para 3 réplicas do `php-fpm`, todos os 3 containers sobem e tentam rodar migrations simultaneamente. Migrations não são idempotentes — duas instâncias tentando executar a mesma migration ao mesmo tempo pode corromper o banco.

**Problema 2 — Lógica contraditória em produção:** O entrypoint cria o cache (`config:cache`, `route:cache`, `view:cache`) e na linha seguinte destrói tudo com `optimize:clear`. O cache é construído na imagem Docker durante o build CI — o entrypoint não precisa recriá-lo, e definitivamente não deveria apagá-lo.

**Como deveria ser:** Migrations rodam uma vez, no pipeline de CI/CD, antes de o container subir. O entrypoint faz apenas `exec "$@"`.

**O que estudar:** Blue-green deployments, migration strategies, 12-factor app (processes are stateless), database migration locking

---

#### DEVOPS CRÍTICO 3: Deploy corre como root no servidor de produção

**Arquivo:** `.github/workflows/deploy.yml`

```yaml
script: |
  cd /root/my-wallet-app   # ← /root = diretório do usuário root
  echo "..." | docker login ghcr.io -u ${{ github.actor }} --password-stdin
  sh ./scripts/deploy.sh
```

**Consequência:** Se um atacante conseguir acesso ao servidor via qualquer vulnerabilidade, ele já está como root — controle total da máquina. Um processo comprometido como root pode modificar qualquer arquivo do sistema, instalar backdoors, ler segredos de outros containers.

**O que estudar:** Principle of least privilege em sistemas operacionais, criação de usuário dedicado para deploy (`deployuser`), sudoers com comandos específicos

---

#### DEVOPS CRÍTICO 4: `queue-worker` monta volume errado em produção

**Arquivo:** `compose.prod.yaml`

```yaml
queue-worker:
  image: ghcr.io/.../php-cli:latest
  volumes:
    - .:/var/www/html   # ← path errado! imagem usa /var/www, não /var/www/html
```

**Duplo problema:**
1. O path `/var/www/html` não existe na imagem — o código não está disponível para o worker
2. Montar `.:` (diretório atual do host) em produção anula o propósito de usar uma imagem Docker com código baked in. O código dentro da imagem é ignorado. Se o diretório do host não tiver o código atualizado, o worker roda código desatualizado.

**O que estudar:** Docker volumes vs bind mounts, imutabilidade de imagens Docker, como código deve chegar em produção

---

### NÍVEL ALTO — Problemas de Segurança e Confiabilidade

---

#### DEVOPS ALTO 1: Banco de dados com porta exposta em produção

**Arquivo:** `compose.prod.yaml`

```yaml
postgres:
  ports:
    - "${DB_PORT}:5432"  # ← expõe o banco na interface de rede pública do servidor
```

**Consequência:** O PostgreSQL fica acessível publicamente na internet (na porta configurada em `DB_PORT`). Qualquer pessoa pode tentar se conectar — brute force de senha, exploração de vulnerabilidades do PostgreSQL. Em produção, o banco deve estar apenas na rede Docker interna, sem `ports:`.

---

#### DEVOPS ALTO 2: Redis sem autenticação em produção

**Arquivo:** `compose.prod.yaml`

```yaml
redis:
  image: redis:alpine
  # ← sem 'command: redis-server --requirepass ...'
  # ← sem senha
```

**Consequência:** Qualquer container na rede `mywallet-production` pode acessar o Redis sem senha. O Redis armazena sessões de usuários — um container comprometido pode ler e forjar sessões de qualquer usuário logado.

**Como corrigir:**
```yaml
redis:
  command: redis-server --requirepass ${REDIS_PASSWORD}
```

**O que estudar:** Redis security, network segmentation in Docker, defense in depth

---

#### DEVOPS ALTO 3: Versão do PostgreSQL diferente entre dev e prod

**Arquivos:** `compose.dev.yaml` e `compose.prod.yaml`

```yaml
# dev:
postgres:
  image: postgres:16

# prod:
postgres:
  image: postgres:15-alpine  # ← versão diferente!
```

**Consequência:** Features do PostgreSQL 16 que você usa em dev podem não existir no 15. Bugs que aparecem em prod mas não em dev (ou vice-versa). Comportamento de queries pode diferir entre versões.

**Regra:** Dev e prod devem usar a mesma versão de todos os serviços, incluindo versão minor (16.2, não apenas 16).

---

#### DEVOPS ALTO 4: Dockerfile não usa layer cache corretamente (rebuild lento desnecessário)

**Arquivo:** `docker/common/php-fpm/Dockerfile`

```dockerfile
# ❌ Copia TUDO antes de instalar dependências
COPY . /var/www
RUN composer install --no-dev ...  # invalida cache em QUALQUER mudança de arquivo
```

**Consequência:** Toda mudança em qualquer arquivo da aplicação (inclusive um comentário num `.vue`) invalida o cache do layer do `composer install` — que baixa todas as dependências novamente. Builds que levam 5 minutos desnecessariamente.

**Como deve ser:**
```dockerfile
# ✅ Copia só o que o composer precisa primeiro
COPY composer.json composer.lock ./
RUN composer install --no-dev ...  # cache válido enquanto composer.lock não mudar

COPY package.json package-lock.json ./
RUN npm ci                          # cache válido enquanto package-lock.json não mudar

COPY . /var/www                     # só depois copia o resto
```

**O que estudar:** Docker layer caching, ordem das instruções no Dockerfile, `npm ci` vs `npm install`

---

#### DEVOPS ALTO 5: `pdo_mysql` instalado mas o app usa PostgreSQL

**Arquivo:** `docker/common/php-fpm/Dockerfile`

```dockerfile
&& docker-php-ext-install -j$(nproc) \
    pdo_mysql \    # ← app não usa MySQL
    pdo_pgsql \
```

**Consequência:** Tempo de build desnecessário, imagem maior que precisa ser. Confusão para quem mantém o projeto — "esse app usa MySQL?"

---

#### DEVOPS ALTO 6: Certificados SSL: paths inconsistentes entre certbot e web

**Arquivo:** `compose.prod.yaml`

```yaml
web:
  volumes:
    - /etc/letsencrypt:/etc/letsencrypt:ro  # ← monta do sistema host

certbot:
  volumes:
    - ./certbot/conf:/etc/letsencrypt       # ← monta de ./certbot/conf no projeto
```

**Consequência:** O `certbot` salva os certificados em `./certbot/conf` (relativo ao projeto no host). O `web` (nginx) lê de `/etc/letsencrypt` no sistema host — **são paths diferentes**. Os certificados gerados pelo certbot não são lidos pelo nginx. HTTPS simplesmente não funciona.

---

#### DEVOPS ALTO 7: `php-fpm-healthcheck` baixado do GitHub em build time

**Arquivo:** `docker/common/php-fpm/Dockerfile`

```dockerfile
RUN curl -o /usr/local/bin/php-fpm-healthcheck \
    https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck
```

**Problemas:**
1. Referência ao branch `master` — o script pode mudar a qualquer momento sem você saber
2. GitHub indisponível = build falha sem motivo relacionado ao seu código
3. Você está executando código não-auditado de internet em seus builds

**Como corrigir:** Copiar o script para dentro do repositório (`docker/scripts/php-fpm-healthcheck`) e usar `COPY`.

---

### NÍVEL MÉDIO — Má práticas e Configurações Fracas

---

#### DEVOPS MÉDIO 1: Nginx sem headers de segurança

**Arquivo:** `docker/production/nginx/nginx.conf`

Completamente ausentes:
```nginx
# Nenhum desses existe no arquivo:
add_header X-Frame-Options "SAMEORIGIN";
add_header X-Content-Type-Options "nosniff";
add_header X-XSS-Protection "1; mode=block";
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains";
add_header Referrer-Policy "strict-origin-when-cross-origin";
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()";
```

**Consequência:** Sem `X-Frame-Options`, o app pode ser embarcado em iframe de outro domínio (clickjacking). Sem HSTS, navegadores não forçam HTTPS em visitas futuras. Sem `X-Content-Type-Options`, browsers podem interpretar mal o tipo de arquivo (MIME sniffing).

**O que estudar:** OWASP Secure Headers, Mozilla SSL Configuration Generator, securityheaders.com

---

#### DEVOPS MÉDIO 2: `client_max_body_size 100M` em produção

**Arquivo:** `docker/production/nginx/nginx.conf`

**O app importa CSVs bancários** — arquivos de menos de 1MB. 100MB de limite permite uploads que podem consumir todo o disco e memória do servidor em um ataque de DoS por upload.

---

#### DEVOPS MÉDIO 3: Sem compressão gzip no nginx

**Arquivo:** `docker/production/nginx/nginx.conf`

Sem `gzip on` e configuração de tipos. Respostas HTML, JSON e JavaScript são enviadas sem compressão — 3-10x maiores que deveriam. Em conexões lentas, isso impacta diretamente o tempo de carregamento.

---

#### DEVOPS MÉDIO 4: CI sem cache de dependências

**Arquivo:** `.github/workflows/laravel-ci.yml`

```yaml
- name: Install Composer dependencies
  run: composer install --prefer-dist --no-progress
  # ← sem cache! baixa TODOS os pacotes a cada execução
```

Cada execução do CI baixa centenas de pacotes PHP do zero. Com cache de Composer, o tempo de CI cai de ~3 minutos para ~20 segundos nesta etapa.

```yaml
# Como deveria ser:
- name: Cache Composer dependencies
  uses: actions/cache@v3
  with:
    path: vendor
    key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
```

---

#### DEVOPS MÉDIO 5: Imagens sem versioning — rollback impossível

**Arquivo:** `.github/workflows/build.yml`

```yaml
tags: ghcr.io/.../php-fpm:latest   # ← só :latest, sem versão imutável
tags: ghcr.io/.../web:latest        # ← mesma coisa
```

A Python API recebe `latest` + SHA do commit — correto. As outras três imagens recebem só `:latest`. Se um deploy quebrar produção, não há como fazer rollback para a imagem anterior — ela foi sobrescrita.

```yaml
# Como deveria ser:
tags: |
  ghcr.io/.../php-fpm:latest
  ghcr.io/.../php-fpm:${{ github.sha }}
```

---

#### DEVOPS MÉDIO 6: nginx.conf na raiz do projeto referencia serviço errado

**Arquivo:** `nginx.conf` (raiz do projeto)

```nginx
fastcgi_pass app:9000;   # ← serviço 'app' não existe
```

O serviço no docker-compose se chama `php-fpm`, não `app`. Este arquivo não funciona e parece ser um template abandonado que deveria ter sido removido ou atualizado.

---

#### DEVOPS MÉDIO 7: `nginx:latest` no compose dev

**Arquivo:** `compose.dev.yaml`

```yaml
web:
  image: nginx:latest   # ← tag mutable, comportamento não-determinístico
```

`:latest` muda quando a equipe do nginx lança uma nova versão. Na próxima vez que você rodar `docker compose pull`, seu nginx pode mudar de versão silenciosamente. Use uma tag específica: `nginx:1.27-alpine`.

---

#### DEVOPS MÉDIO 8: Workspace tem sudo sem senha

**Arquivo:** `docker/development/workspace/Dockerfile`

```dockerfile
RUN usermod -aG sudo www && \
    echo 'www ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers
```

Para desenvolvimento local, aceitável. Mas se você um dia colocar o workspace container acessível via rede (para colaboração remota, por exemplo), qualquer pessoa com acesso ao container tem controle total do host via `sudo`.

---

### RESUMO: O QUE ESTUDAR (DevOps)

| Erro encontrado | Tópico a estudar | Recurso |
|---|---|---|
| CI desconectado do Build | GitHub Actions `needs:`, branch protection | docs.github.com/actions |
| Migrate no entrypoint com race condition | Zero-downtime migrations, atomic deploys | Martin Fowler — Evolutionary Database Design |
| Deploy como root | Linux user management, principle of least privilege | linuxhandbook.com/sudoers |
| Redis sem senha | Redis AUTH, Docker secrets | redis.io/security |
| Docker layer cache incorreto | Dockerfile best practices | docs.docker.com/develop/dockerfile_best-practices |
| Sem security headers no nginx | OWASP Secure Headers Project | owasp.org/headers |
| Sem image versioning | Docker image tagging strategies, rollback | semver.org |
| Certbot paths inconsistentes | Let's Encrypt com Docker, volume mounting | certbot.eff.org |

---