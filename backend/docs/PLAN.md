# Plano: StudyFlow — Planejador de Estudos SaaS Multi-Tenant



## PARTE 1: A PERGUNTA DO ALGORITMO

### Anki serve? Parcialmente.

O Anki usa o **SM-2 (SuperMemo 2)** — um algoritmo de **repetição espaçada** projetado para flashcards de memorização. Ele responde à pergunta: *"quando devo rever este card para não esquecer?"*

Seu sistema precisa responder a uma pergunta diferente: *"o que eu devo estudar esta semana, e em qual ordem?"*

Você precisa de **dois algoritmos combinados**:

---

### Algoritmo 1: SM-2 (para cards de revisão)

Usado exclusivamente nos cards do tipo **revisão**. Calcula quando o card deve aparecer novamente com base na qualidade da resposta (0 a 5).

```
Entrada: qualidade (0-5), repetições, fator_facilidade, intervalo_atual

Se qualidade < 3:            ← errou ou foi muito difícil
    repetições = 0
    intervalo = 1 dia

Senão:                       ← acertou
    Se repetições == 0: intervalo = 1
    Se repetições == 1: intervalo = 6
    Senão: intervalo = intervalo * fator_facilidade

    fator_facilidade += 0.1 - (5 - qualidade) * (0.08 + (5 - qualidade) * 0.02)
    fator_facilidade = max(1.3, fator_facilidade)  ← nunca abaixo de 1.3
    repetições += 1

próxima_revisão = hoje + intervalo dias
```

**Tradução prática:** Se você acertou fácil (qualidade=5), o card volta em 10+ dias. Se errou (qualidade=1), volta amanhã. O intervalo cresce exponencialmente com acertos.

---

### Algoritmo 2: Priority Score (para planejamento semanal)

Para os cards de **teoria** e **questões**, você não tem "quando devo ver?", mas sim "o que é mais urgente estudar?". Use um **score de prioridade ponderado**:

```
score = (dias_sem_estudar      × 0.30)   ← urgência temporal
      + (dificuldade           × 0.25)   ← temas difíceis merecem mais tempo
      + (progresso_inverso     × 0.20)   ← assuntos pouco avançados têm prioridade
      + (proximidade_prazo     × 0.15)   ← deadline ou prova se aproximando
      + (extensão_restante     × 0.10)   ← quanto ainda falta no tema
```

Cada fator é normalizado 0-1. O resultado é um float de prioridade que você usa para ordenar o que entra na semana.

---

### Como o planejamento semanal funciona (o "algoritmo da semana")

```
ENTRADA:
  - Cards de revisão com next_review_at <= fim_da_semana  (SM-2 obrigados)
  - Todos os cards de teoria e questões ativos            (por score de prioridade)
  - Horas disponíveis por dia (configurado pelo usuário)

PASSO 1 — Selecionar cards
  1. Pegar TODOS os cards de revisão devidos essa semana (obrigatórios)
  2. Calcular score de prioridade de todos os cards de teoria/questão
  3. Ordenar por score DESC
  4. Selecionar até preencher a capacidade horária da semana

PASSO 2 — Distribuir pelos dias (bin-packing guloso)
  Para cada dia da semana:
    - Reservar slots para revisões devidas NAQUELE dia primeiro
    - Completar com teoria/questões em ordem de prioridade
    - Respeitar o limite de minutos por dia do usuário
    - Intercalar tipos (não colocar 4h seguidas de teoria)

PASSO 3 — Persistir
  Criar weekly_plan + plan_cards com scheduled_date para cada card

SAÍDA: WeeklyPlan com cards distribuídos por dia
```

---

### O que acontece quando o usuário não faz o card no dia (replanning)

```
Job diário (00:05) — RescheduleMissedCardsJob:

Para cada plan_card com scheduled_date < hoje AND status = 'pending':

  Se card.type == 'review':
    → Atualizar card_review_progress com quality=0 (SM-2 reseta)
    → Reagendar para o próximo slot disponível na semana
    → Aumentar peso do score em 50% (urgência aumentada)

  Se card.type == 'teoria' ou 'questao':
    → Recalcular score (dias_sem_estudar aumentou → score sobe)
    → Mover para próximo slot livre na semana atual
    → Se semana cheia, escalar para segunda-feira da próxima semana

Após reagendar: disparar evento CardRescheduled
```

---

## PARTE 2: DESIGN PATTERNS NECESSÁRIOS

### 1. Strategy Pattern — O coração do algoritmo

**Por quê:** Você tem 3 tipos de cards com comportamentos completamente diferentes (SM-2, priority score, questões). Strategy permite trocar o algoritmo sem mudar o código ao redor.

```php
// app/Contracts/CardSchedulingStrategy.php
interface CardSchedulingStrategy
{
    public function calculateNextDate(CardSession $session): Carbon;
    public function calculatePriority(StudyCard $card, User $user): float;
}

// app/Services/Scheduling/SM2ReviewStrategy.php
class SM2ReviewStrategy implements CardSchedulingStrategy
{
    public function calculateNextDate(CardSession $session): Carbon
    {
        // implementação do SM-2
    }

    public function calculatePriority(StudyCard $card, User $user): float
    {
        // reviews devidos hoje têm prioridade máxima
        $daysOverdue = now()->diffInDays($card->nextReviewProgress($user)->next_review_at);
        return min(1.0, $daysOverdue / 7);
    }
}

// app/Services/Scheduling/TheoryStudyStrategy.php
class TheoryStudyStrategy implements CardSchedulingStrategy { ... }

// app/Services/Scheduling/PracticeQuestionStrategy.php
class PracticeQuestionStrategy implements CardSchedulingStrategy { ... }
```

**Quando NÃO usar:** Se todos os cards tivessem o mesmo algoritmo, Strategy seria overhead. Aqui é necessário.

---

### 2. Events + Listeners — Replanning reativo

**Por quê:** Quando o usuário conclui um card, várias coisas devem acontecer de forma desacoplada: atualizar progresso SM-2, verificar se o plano precisa ser ajustado, registrar sessão. Colocar tudo no controller seria um desastre.

```php
// Eventos que o sistema dispara:
CardCompleted::class   → [UpdateSM2Progress::class, LogCardSession::class]
CardSkipped::class     → [RescheduleMissedCard::class, LogCardSession::class]
WeekStarted::class     → [GenerateWeeklyPlan::class]
```

**Quando NÃO usar:** Para side effects síncronos simples que precisam do resultado na mesma request.

---

### 3. Jobs + Laravel Scheduler — Planejamento assíncrono

**Por quê:** Gerar o plano semanal pode ser pesado (muitos cards, cálculos de score). Fazer isso na request do usuário trava a UI. Jobs resolvem isso assincronamente.

```php
// app/Jobs/GenerateWeeklyPlanJob.php
class GenerateWeeklyPlanJob implements ShouldQueue
{
    public function handle(WeeklyPlannerService $planner): void
    {
        $planner->generateForUser($this->user, $this->weekStart);
    }
}

// app/Jobs/RescheduleMissedCardsJob.php
class RescheduleMissedCardsJob implements ShouldQueue { ... }

// routes/console.php (Laravel 11+)
Schedule::job(GenerateWeeklyPlanJob::class)->weekly()->sundays()->at('20:00');
Schedule::job(RescheduleMissedCardsJob::class)->daily()->at('00:05');
```

**Quando NÃO usar:** Para operações simples e rápidas. Não transforme todo o sistema em filas.

---

### 4. Action Pattern — Operações atômicas

```php
// app/Actions/CompleteCardAction.php
final class CompleteCardAction
{
    public function handle(PlanCard $planCard, int $quality, int $timeSpentMinutes): CardSession
    {
        return DB::transaction(function () use ($planCard, $quality, $timeSpentMinutes) {
            $session = CardSession::create([...]);
            $planCard->update(['status' => 'completed', 'completed_at' => now()]);
            event(new CardCompleted($planCard, $quality));
            return $session;
        });
    }
}
```

---

### 5. Global Scopes para Multi-tenancy

Mesma abordagem discutida anteriormente — trait `BelongsToTenant` em todos os models.

---

## PARTE 3: TECNOLOGIAS NECESSÁRIAS

| Tecnologia | Para quê | Por quê é necessário |
|---|---|---|
| **Laravel 12** | Framework base | — |
| **PostgreSQL 16** | Persistência | JSON columns para armazenar parâmetros SM-2 |
| **Redis** | Queue backend + cache | Jobs assíncronos + cache do plano semanal |
| **Laravel Horizon** | Monitorar filas | Ver jobs de replanning falhando/lento |
| **Laravel Scheduler** | Trigger automático | `GenerateWeeklyPlan` todo domingo, `RescheduleMissed` diário |
| **Vue 3 (SPA)**        | Frontend reativo            | SPA desacoplada, Composition API, Pinia para estado  |
| **Pinia**              | Gerenciamento de estado     | Estado do plano semanal, progresso dos cards         |
| **Vue Router**         | Roteamento client-side      | Navegação entre dashboard, cards, configurações      |
| **Axios**              | HTTP client                 | Consome a API Laravel com interceptors de auth       |
| **Laravel Sanctum**    | Autenticação API via token  | Emite tokens para o SPA, substituindo session auth   |

**Sem overengineering:** Não precisa de microservices, GraphQL, WebSockets (polling simples resolve), ML/AI externo.

---

## PARTE 4: SCHEMA DO BANCO DE DADOS

```sql
tenants          (id, name, subdomain, plan, settings:json, created_at)
users            (id, tenant_id, name, email, role, study_minutes_per_day, timezone)

subjects         (id, tenant_id, user_id, name, color, active)
topics           (id, subject_id, name, difficulty[1-5], estimated_hours, description)

study_cards      (id, topic_id, type[theory|review|question], title, content,
                  difficulty[1-5], estimated_minutes, is_active)

-- Progresso SM-2 (uma linha por user × card de revisão)
card_review_progress (id, user_id, card_id, repetitions, easiness_factor,
                      interval_days, next_review_at)

-- O plano da semana
weekly_plans     (id, user_id, week_start, week_end, status[draft|active|completed],
                  generated_at)
plan_cards       (id, weekly_plan_id, card_id, scheduled_date, scheduled_order,
                  status[pending|completed|skipped|rescheduled], completed_at,
                  rescheduled_count)

-- Histórico de sessões de estudo
card_sessions    (id, user_id, card_id, plan_card_id, quality[0-5],
                  time_spent_minutes, notes, created_at)
```

---

## PARTE 5: ARQUITETURA

```
Browser (Vue 3 SPA — frontend/)
      │  Axios + Bearer Token
      ▼
[Nginx]
      ├── /api/* → PHP-FPM (Laravel backend/)
      └── /*     → Vue SPA (arquivos estáticos)
      │
      ▼
[Laravel API]
      │
      ├── Middleware: IdentifyTenant (resolve por subdomain)
      ├── Middleware: auth:sanctum (valida token)
      │
      ├── HTTP Layer
      │   ├── Requests/            (Form Requests + authorize())
      │   ├── Controllers/         (thin — retornam JsonResource)
      │   └── Resources/           (serialização — nunca expõe model bruto)
      │
      ├── Domain Layer
      │   ├── Actions/
      │   │   ├── CompleteCardAction.php
      │   │   ├── SkipCardAction.php
      │   │   └── RegeneratePlanAction.php
      │   │
      │   ├── Services/
      │   │   ├── WeeklyPlannerService.php   ← algoritmo principal
      │   │   ├── SM2Service.php             ← implementação SM-2
      │   │   └── PriorityScoreService.php   ← score dos cards
      │   │
      │   └── Contracts/
      │       └── CardSchedulingStrategy.php
      │
      ├── Jobs/
      │   ├── GenerateWeeklyPlanJob.php
      │   └── RescheduleMissedCardsJob.php
      │
      ├── Events/
      │   ├── CardCompleted.php
      │   ├── CardSkipped.php
      │   └── WeeklyPlanGenerated.php
      │
      └── Data Layer
          ├── Repositories/
          │   ├── Contracts/               (interfaces)
          │   ├── PlanCardRepository.php
          │   └── StudyCardRepository.php
          └── Models/ (com BelongsToTenant trait)
                │
                ├── [PostgreSQL] — dados
                └── [Redis]     — queue + cache do plano
```

---

## PARTE 6: ESTRUTURA DE DIRETÓRIOS

```
agenda-hub/                    ← raiz do repositório
├── backend/                   ← Laravel (API pura)
│   ├── app/
│   ├── ...
│   └── Dockerfile
├── frontend/                  ← Vue 3 SPA
│   ├── src/
│   │   ├── api/               ← módulos Axios por recurso
│   │   ├── components/
│   │   ├── pages/
│   │   ├── router/
│   │   ├── stores/            ← Pinia stores
│   │   └── main.js
│   ├── Dockerfile
│   └── vite.config.js
└── docker-compose.yml
```

### Estrutura interna do backend/

```
app/
├── Actions/
│   ├── Card/
│   │   ├── CompleteCardAction.php
│   │   └── SkipCardAction.php
│   └── Plan/
│       └── RegeneratePlanAction.php
│
├── Contracts/
│   └── CardSchedulingStrategy.php        ← interface Strategy
│
├── Events/
│   ├── CardCompleted.php
│   ├── CardSkipped.php
│   └── WeeklyPlanGenerated.php
│
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── WeeklyPlanController.php
│   │   ├── StudyCardController.php
│   │   └── TopicController.php
│   ├── Middleware/
│   │   └── IdentifyTenant.php
│   └── Requests/
│       ├── CompleteCardRequest.php
│       └── StoreTopicRequest.php
│
├── Jobs/
│   ├── GenerateWeeklyPlanJob.php
│   └── RescheduleMissedCardsJob.php
│
├── Listeners/
│   ├── UpdateSM2Progress.php
│   ├── RescheduleMissedCard.php
│   └── LogCardSession.php
│
├── Models/
│   ├── Traits/
│   │   └── BelongsToTenant.php
│   ├── Tenant.php
│   ├── User.php
│   ├── Subject.php
│   ├── Topic.php
│   ├── StudyCard.php
│   ├── CardReviewProgress.php
│   ├── WeeklyPlan.php
│   ├── PlanCard.php
│   └── CardSession.php
│
├── Observers/
│   └── PlanCardObserver.php
│
├── Policies/
│   ├── StudyCardPolicy.php
│   └── WeeklyPlanPolicy.php
│
├── Repositories/
│   ├── Contracts/
│   │   ├── PlanCardRepositoryInterface.php
│   │   └── StudyCardRepositoryInterface.php
│   ├── EloquentPlanCardRepository.php
│   └── EloquentStudyCardRepository.php
│
└── Services/
    ├── Scheduling/
    │   ├── SM2ReviewStrategy.php
    │   ├── TheoryStudyStrategy.php
    │   └── PracticeQuestionStrategy.php
    ├── SM2Service.php
    ├── PriorityScoreService.php
    └── WeeklyPlannerService.php
```

---

## PARTE 7: ROADMAP (4 semanas)

### Semana 1 — Fundação + Multi-tenancy + CRUD base

**Objetivo:** App rodando, tenant isolation funcionando, subjects/topics/cards com CRUD.

```
feat(setup): inicializa Laravel 12 com Pest, PostgreSQL, Redis
feat(tenant): BelongsToTenant global scope + IdentifyTenant middleware
feat(auth): POST /api/auth/login retorna token Sanctum (manual, sem starter kit)
feat(auth): POST /api/auth/logout revoga token
feat(auth): GET /api/auth/me retorna usuário autenticado
test(auth): testa login, logout, token inválido, tenant errado
feat(subjects): CRUD de matérias e tópicos com policies
feat(cards): CRUD de study_cards com tipo (theory/review/question)
test(tenant): testa isolamento de dados entre tenants
```

**Anti-patterns a evitar:**
- Não comece pelo frontend/dashboard. Comece pelos models e testes.
- Todo model com tenant_id usa o trait BelongsToTenant.

---

### Semana 2 — Algoritmo de Planejamento

**Objetivo:** SM-2 funcionando, geração do plano semanal via Job, score de prioridade.

```
feat(sm2): implementa SM2Service com lógica completa e testes unitários
feat(priority): implementa PriorityScoreService com pesos configuráveis
feat(planner): implementa WeeklyPlannerService (bin-packing semanal)
feat(jobs): GenerateWeeklyPlanJob + RescheduleMissedCardsJob
feat(scheduler): configura Schedule no console.php
feat(events): CardCompleted e CardSkipped com listeners
test(sm2): testa todos os casos do algoritmo SM-2 (quality 0-5)
test(planner): testa geração do plano com diferentes cenários
```

---

### Semana 3 — Dashboard + Interação + Replanning

**Objetivo:** Frontend do dashboard semanal, ações de completar/pular, replanning funcionando.

```
feat(frontend): setup Vue 3 SPA em frontend/ com Vite, Vue Router, Pinia, Axios
feat(frontend): interceptor Axios para Bearer token + redirect ao 401
feat(dashboard): componente WeeklyPlan consumindo GET /api/plan/current
feat(complete): CompleteCardAction via POST /api/cards/{id}/complete
feat(skip): SkipCardAction com reagendamento imediato

**Anti-patterns a evitar:**
- Não exponha o model User bruto via API. Sempre use UserResource.
- Valide CORS antes de começar o frontend (config/cors.php).
feat(replan): RegeneratePlanAction para forçar novo plano
feat(horizon): configura Horizon para monitorar jobs
test(complete): testa fluxo completo card → SM-2 update → session log
test(replan): testa reagendamento de cards perdidos
```

---

### Semana 4 — DevOps + Deploy

**Objetivo:** Docker production-ready, CI/CD correto, rodando em produção.

```
chore(docker): Dockerfile multi-stage com layer cache correto
chore(ci): GitHub Actions test → build → deploy (conectados com needs:)
chore(deploy): usuário dedicado (não root), script de zero-downtime
feat(monitoring): Sentry + Laravel Pulse
chore(nginx): headers de segurança, gzip, client_max_body_size correto
```

---

## PARTE 8: SNIPPETS DOS PADRÕES MAIS IMPORTANTES

### Autenticação Manual com Sanctum

```php
// routes/api.php
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

// app/Http/Controllers/AuthController.php
public function login(LoginRequest $request): JsonResponse
{
    if (!Auth::attempt($request->only('email', 'password'))) {
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    $token = $request->user()->createToken('spa')->plainTextToken;

    return response()->json(['token' => $token]);
}
```

```js
// frontend/src/api/auth.js
import axios from './client'

export const login = (credentials) => axios.post('/auth/login', credentials)
export const logout = () => axios.post('/auth/logout')
export const me = () => axios.get('/auth/me')

// frontend/src/api/client.js
const client = axios.create({ baseURL: import.meta.env.VITE_API_URL })

client.interceptors.request.use(config => {
    const token = localStorage.getItem('token')
    if (token) config.headers.Authorization = `Bearer ${token}`
    return config
})

client.interceptors.response.use(
    res => res,
    err => {
        if (err.response?.status === 401) router.push('/login')
        return Promise.reject(err)
    }
)
```

### SM2Service.php

```php
// app/Services/SM2Service.php
final class SM2Service
{
    public function calculate(int $quality, CardReviewProgress $progress): SM2Result
    {
        $repetitions = $progress->repetitions;
        $ef = $progress->easiness_factor;  // começa em 2.5
        $interval = $progress->interval_days;

        if ($quality < 3) {
            $repetitions = 0;
            $interval = 1;
        } else {
            $interval = match ($repetitions) {
                0 => 1,
                1 => 6,
                default => (int) round($interval * $ef),
            };
            $repetitions++;
        }

        $ef = max(1.3, $ef + 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));

        return new SM2Result(
            repetitions: $repetitions,
            easinessFactor: $ef,
            intervalDays: $interval,
            nextReviewAt: now()->addDays($interval),
        );
    }
}
```

### WeeklyPlannerService.php (estrutura)

```php
// app/Services/WeeklyPlannerService.php
final class WeeklyPlannerService
{
    public function generateForUser(User $user, Carbon $weekStart): WeeklyPlan
    {
        $weekEnd = $weekStart->copy()->endOfWeek();
        $dailyMinutes = $user->study_minutes_per_day;

        // 1. Busca cards de revisão obrigatórios (SM-2 due)
        $reviewCards = $this->cardRepository->getDueReviews($user, $weekEnd);

        // 2. Busca e ordena cards de teoria/questão por score
        $studyCards = $this->scoreService->rankCards($user);

        // 3. Bin-packing: distribui pelos dias
        $schedule = $this->distributeAcrossDays(
            $reviewCards, $studyCards, $weekStart, $dailyMinutes
        );

        // 4. Persiste
        return DB::transaction(fn() => $this->persistPlan($user, $weekStart, $schedule));
    }

    private function distributeAcrossDays(
        Collection $reviews, Collection $studyCards,
        Carbon $weekStart, int $dailyMinutes
    ): array {
        $slots = [];  // [date => minutes_used]
        $plan = [];   // [date => [card_ids]]

        foreach (CarbonPeriod::create($weekStart, 6) as $day) {
            $slots[$day->toDateString()] = 0;
            $plan[$day->toDateString()] = [];
        }

        // Primeiro: encaixar reviews nos dias devidos
        foreach ($reviews as $card) {
            $date = $card->progress->next_review_at->toDateString();
            if (isset($slots[$date]) && $slots[$date] + $card->estimated_minutes <= $dailyMinutes) {
                $plan[$date][] = $card->id;
                $slots[$date] += $card->estimated_minutes;
            }
        }

        // Depois: encaixar teoria/questões no espaço restante
        foreach ($studyCards as $card) {
            foreach ($plan as $date => &$cards) {
                if ($slots[$date] + $card->estimated_minutes <= $dailyMinutes) {
                    $cards[] = $card->id;
                    $slots[$date] += $card->estimated_minutes;
                    break;
                }
            }
        }

        return $plan;
    }
}
```

### RescheduleMissedCardsJob.php

```php
// app/Jobs/RescheduleMissedCardsJob.php
class RescheduleMissedCardsJob implements ShouldQueue
{
    public function handle(PlanCardRepository $repo, SM2Service $sm2): void
    {
        $missed = $repo->getMissedCards(today());

        foreach ($missed as $planCard) {
            if ($planCard->card->type === 'review') {
                // SM-2 reseta com quality=0
                $progress = $planCard->card->reviewProgress($planCard->plan->user);
                $result = $sm2->calculate(0, $progress);
                $progress->update($result->toArray());
            }

            event(new CardSkipped($planCard));
        }
    }
}
```

---

## PARTE 9: VALIDAÇÃO DE DOMÍNIO

### Você está dominando quando consegue:

**Algoritmo:**
- [ ] Implementar SM-2 do zero com testes que cobrem todos os casos de quality 0-5
- [ ] Explicar por que o fator de facilidade não pode ser menor que 1.3
- [ ] Ajustar os pesos do priority score e justificar a mudança

**Laravel:**
- [ ] Adicionar um 4º tipo de card sem mudar WeeklyPlannerService (só nova Strategy)
- [ ] Escrever um teste que prova que o plano respeita o limite diário de minutos
- [ ] Debugar um job que falhou no Horizon sem `dd()`

**Arquitetura:**
- [ ] Explicar por que Strategy e não if/else para os tipos de card
- [ ] Adicionar um novo Event sem tocar em nenhum arquivo existente
- [ ] Garantir que tenant A nunca vê cards do tenant B — com teste provando isso

---

## PARTE 10: DIFERENCIAL DE PORTFÓLIO

**CRUD comum:** Formulário salva no banco. Tabela lista. Botão deleta.

**Seu projeto demonstra:**
1. **Algoritmo próprio** (SM-2 + priority scoring) — candidatos raramente têm isso
2. **Event-driven com replanning reativo** — arquitetura que responde a eventos reais
3. **Laravel Scheduler + Jobs em produção** — não apenas CRUD, mas processos assíncronos reais
4. **Multi-tenancy testado** — dados isolados por tenant com testes provando isso
5. **Strategy Pattern aplicado a problema real** — não apenas padrão de livro

**Potencial SaaS:** O modelo multi-tenant está pronto. Para monetizar:
- `plan` column em `tenants` (free/pro/enterprise)
- Gate baseado no plano: `Gate::define('advanced-analytics', fn($user) => $user->tenant->plan === 'pro')`
- Stripe Billing quando quiser adicionar pagamentos (Laravel Cashier)
