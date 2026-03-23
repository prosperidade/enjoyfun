# Lógica de Alertas e Cálculos Automáticos — Módulo 1

> Stack: Node.js / TypeScript  
> Localização sugerida: `src/modules/logistics/services/alert-calculator.service.ts`

---

## Visão geral

O motor de alertas é acionado automaticamente sempre que:

- A timeline (`artist_operational_timeline`) é criada ou atualizada
- Uma estimativa de deslocamento (`artist_transfer_estimations`) é criada, atualizada ou removida
- O horário de apresentação (`event_artists.performance_start_datetime`) é alterado
- O endpoint `POST /alerts/recalculate` é chamado manualmente

O resultado é um conjunto de registros em `artist_operational_alerts`, com os alertas anteriores **substituídos** (não acumulados).

---

## Tipos de alerta

| `alert_type` | Descrição |
|---|---|
| `tight_arrival` | Chegada operacional prevista conflita com soundcheck ou show |
| `tight_departure` | Saída do evento conflita com próximo compromisso |
| `soundcheck_conflict` | Não há tempo suficiente entre chegada e soundcheck |
| `stage_conflict` | Chegada prevista é posterior ao horário do palco |
| `transfer_risk` | ETA de algum trecho não foi informado (dado insuficiente) |
| `insufficient_data` | Faltam campos críticos para calcular qualquer janela |

---

## Classificação de severidade e cor

| `severity` | `color_status` | Condição |
|---|---|---|
| `low` | `green` | Todos os buffers > 30 min |
| `medium` | `yellow` | Algum buffer entre 15–30 min |
| `high` | `orange` | Algum buffer < 15 min |
| `critical` | `red` | Chegada prevista após horário do palco |
| — | `gray` | Dados insuficientes para calcular |

---

## Implementação — AlertCalculatorService

```typescript
// src/modules/logistics/services/alert-calculator.service.ts

import { Injectable } from '@nestjs/common';
import { addMinutes, differenceInMinutes, isAfter } from 'date-fns';
import { PrismaService } from '@/prisma/prisma.service';

export type AlertSeverity = 'low' | 'medium' | 'high' | 'critical';
export type AlertColor = 'green' | 'yellow' | 'orange' | 'red' | 'gray';

interface AlertResult {
  alert_type: string;
  severity: AlertSeverity;
  color_status: AlertColor;
  message: string;
  recommended_action: string;
}

@Injectable()
export class AlertCalculatorService {
  constructor(private readonly prisma: PrismaService) {}

  // Ponto de entrada principal — chamado após qualquer alteração na timeline ou transfers
  async recalculate(
    eventArtistId: string,
    organizerId: string
  ): Promise<void> {
    const timeline = await this.prisma.artist_operational_timeline.findFirst({
      where: { event_artist_id: eventArtistId, organizer_id: organizerId }
    });

    const transfers = await this.prisma.artist_transfer_estimations.findMany({
      where: { event_artist_id: eventArtistId, organizer_id: organizerId }
    });

    const eventArtist = await this.prisma.event_artists.findFirst({
      where: { id: eventArtistId, organizer_id: organizerId }
    });

    // Limpa alertas anteriores não resolvidos manualmente
    await this.prisma.artist_operational_alerts.deleteMany({
      where: {
        event_artist_id: eventArtistId,
        organizer_id: organizerId,
        is_resolved: false
      }
    });

    if (!timeline || !eventArtist?.performance_start_datetime) {
      await this.createAlert(eventArtistId, organizerId, {
        alert_type: 'insufficient_data',
        severity: 'low',
        color_status: 'gray',
        message: 'Dados insuficientes para calcular a janela operacional.',
        recommended_action: 'Preencha a linha do tempo e o horário de apresentação.'
      });
      return;
    }

    const alerts = this.calculateAlerts(timeline, transfers, eventArtist);

    for (const alert of alerts) {
      await this.createAlert(eventArtistId, organizerId, alert);
    }
  }

  private calculateAlerts(
    timeline: any,
    transfers: any[],
    eventArtist: any
  ): AlertResult[] {
    const alerts: AlertResult[] = [];

    const performanceStart = new Date(eventArtist.performance_start_datetime);
    const performanceEnd = eventArtist.performance_end_datetime
      ? new Date(eventArtist.performance_end_datetime)
      : addMinutes(performanceStart, eventArtist.performance_duration_min ?? 60);

    // -----------------------------------------------
    // JANELA 1: Chegada → Soundcheck
    // -----------------------------------------------
    if (timeline.arrival_datetime && timeline.soundcheck_datetime) {
      const arrivalToSoundcheck = differenceInMinutes(
        new Date(timeline.soundcheck_datetime),
        new Date(timeline.arrival_datetime)
      );
      // Subtrai ETA do transfer aeroporto → venue, se existir
      const transferEta = this.getTransferEta(transfers, 'airport_to_venue');
      const effectiveBuffer = transferEta
        ? arrivalToSoundcheck - transferEta
        : arrivalToSoundcheck;

      if (effectiveBuffer < 0) {
        alerts.push({
          alert_type: 'soundcheck_conflict',
          severity: 'critical',
          color_status: 'red',
          message: `Chegada prevista (${this.fmt(timeline.arrival_datetime)}) é posterior ao horário do soundcheck (${this.fmt(timeline.soundcheck_datetime)}).`,
          recommended_action: 'Cancelar ou reduzir o soundcheck. Verificar possibilidade de antecipar chegada ou ir direto do aeroporto ao venue.'
        });
      } else if (effectiveBuffer < 15) {
        alerts.push({
          alert_type: 'soundcheck_conflict',
          severity: 'high',
          color_status: 'orange',
          message: `Buffer entre chegada e soundcheck é de apenas ${effectiveBuffer} minutos.`,
          recommended_action: 'Antecipar transfer ou reduzir soundcheck. Acompanhar chegada em tempo real.'
        });
      } else if (effectiveBuffer < 30) {
        alerts.push({
          alert_type: 'soundcheck_conflict',
          severity: 'medium',
          color_status: 'yellow',
          message: `Buffer entre chegada e soundcheck é de ${effectiveBuffer} minutos (recomendado: 30+).`,
          recommended_action: 'Monitorar. Ter plano B para redução do soundcheck se necessário.'
        });
      }
    }

    // -----------------------------------------------
    // JANELA 2: Chegada → Início do show
    // -----------------------------------------------
    if (timeline.arrival_datetime) {
      const transferEta = this.getTransferEta(transfers, 'airport_to_venue')
        ?? this.getTransferEta(transfers, 'hotel_to_venue')
        ?? 0;

      const estimatedVenueArrival = timeline.venue_arrival_datetime
        ? new Date(timeline.venue_arrival_datetime)
        : addMinutes(new Date(timeline.arrival_datetime), transferEta);

      const bufferToShow = differenceInMinutes(performanceStart, estimatedVenueArrival);

      if (isAfter(estimatedVenueArrival, performanceStart)) {
        alerts.push({
          alert_type: 'stage_conflict',
          severity: 'critical',
          color_status: 'red',
          message: `Chegada prevista ao venue (${this.fmt(estimatedVenueArrival.toISOString())}) é posterior ao início do show (${this.fmt(eventArtist.performance_start_datetime)}).`,
          recommended_action: 'CRÍTICO: Avaliar alteração de horário de palco, transfer por helicóptero ou outra solução emergencial.'
        });
      } else if (bufferToShow < 15) {
        alerts.push({
          alert_type: 'tight_arrival',
          severity: 'high',
          color_status: 'orange',
          message: `Buffer entre chegada ao venue e início do show é de apenas ${bufferToShow} minutos.`,
          recommended_action: 'Antecipar transfer. Preparar camarim com antecedência máxima. Ter coordenador na chegada.'
        });
      } else if (bufferToShow < 30) {
        alerts.push({
          alert_type: 'tight_arrival',
          severity: 'medium',
          color_status: 'yellow',
          message: `Buffer entre chegada e show é de ${bufferToShow} minutos.`,
          recommended_action: 'Monitorar chegada. Ter camarim e produção prontos antes da chegada.'
        });
      }
    }

    // -----------------------------------------------
    // JANELA 3: Fim do show → Próximo compromisso
    // -----------------------------------------------
    if (timeline.next_departure_deadline) {
      const departureDeadline = new Date(timeline.next_departure_deadline);
      const transferEta = this.getTransferEta(transfers, 'venue_to_airport')
        ?? this.getTransferEta(transfers, 'venue_to_next_event')
        ?? 0;

      const latestDeparture = addMinutes(departureDeadline, -transferEta);
      const bufferAfterShow = differenceInMinutes(latestDeparture, performanceEnd);

      if (bufferAfterShow < 0) {
        alerts.push({
          alert_type: 'tight_departure',
          severity: 'critical',
          color_status: 'red',
          message: `Impossível chegar ao próximo destino a tempo. Buffer após o show: ${bufferAfterShow} minutos.`,
          recommended_action: 'CRÍTICO: Operação impraticável no formato atual. Avaliar: encurtar show, transfer especial, alterar horário do voo/próximo evento.'
        });
      } else if (bufferAfterShow < 15) {
        alerts.push({
          alert_type: 'tight_departure',
          severity: 'high',
          color_status: 'orange',
          message: `Buffer entre fim do show e saída necessária é de apenas ${bufferAfterShow} minutos.`,
          recommended_action: 'Preparar saída imediata pós-show. Transfer aguardando no backstage. Sem tempo para meet & greet.'
        });
      } else if (bufferAfterShow < 30) {
        alerts.push({
          alert_type: 'tight_departure',
          severity: 'medium',
          color_status: 'yellow',
          message: `Buffer de saída é de ${bufferAfterShow} minutos.`,
          recommended_action: 'Agilizar desmontagem. Comunicar equipe sobre saída rápida.'
        });
      }
    }

    // -----------------------------------------------
    // JANELA 4: ETA de deslocamento não informado
    // -----------------------------------------------
    const criticalRoutes = ['airport_to_venue', 'venue_to_airport', 'venue_to_next_event'];
    for (const route of criticalRoutes) {
      const transfer = transfers.find(t => t.route_type === route);
      if (!transfer && this.routeIsRelevant(timeline, route)) {
        alerts.push({
          alert_type: 'transfer_risk',
          severity: 'medium',
          color_status: 'yellow',
          message: `Estimativa de deslocamento "${this.routeLabel(route)}" não foi informada.`,
          recommended_action: `Cadastrar ETA do trecho "${this.routeLabel(route)}" para cálculo preciso da janela.`
        });
      }
    }

    // Se não há alertas de problema, criar alerta verde (confortável)
    if (alerts.length === 0) {
      alerts.push({
        alert_type: 'tight_arrival', // usa como base, severity = low = green
        severity: 'low',
        color_status: 'green',
        message: 'Todas as janelas operacionais estão confortáveis.',
        recommended_action: 'Nenhuma ação necessária.'
      });
    }

    return alerts;
  }

  // -----------------------------------------------
  // Helpers
  // -----------------------------------------------

  private getTransferEta(transfers: any[], routeType: string): number | null {
    const t = transfers.find(t => t.route_type === routeType);
    return t?.planned_eta_minutes ?? null;
  }

  private routeIsRelevant(timeline: any, route: string): boolean {
    if (route === 'airport_to_venue') return !!timeline.arrival_airport;
    if (route === 'venue_to_airport') return timeline.next_commitment_type === 'airport';
    if (route === 'venue_to_next_event') return timeline.next_commitment_type === 'event';
    return false;
  }

  private routeLabel(route: string): string {
    const labels: Record<string, string> = {
      airport_to_venue: 'Aeroporto → Venue',
      venue_to_airport: 'Venue → Aeroporto',
      venue_to_next_event: 'Venue → Próximo Evento',
      hotel_to_venue: 'Hotel → Venue',
      airport_to_hotel: 'Aeroporto → Hotel'
    };
    return labels[route] ?? route;
  }

  private fmt(isoDate: string): string {
    return new Date(isoDate).toLocaleTimeString('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: 'America/Sao_Paulo'
    });
  }

  private async createAlert(
    eventArtistId: string,
    organizerId: string,
    alert: AlertResult
  ): Promise<void> {
    await this.prisma.artist_operational_alerts.create({
      data: {
        event_artist_id: eventArtistId,
        organizer_id: organizerId,
        ...alert,
        is_resolved: false
      }
    });
  }
}
```

---

## Acionamento automático nos services

```typescript
// src/modules/logistics/services/timeline.service.ts

@Injectable()
export class TimelineService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly alertCalculator: AlertCalculatorService
  ) {}

  async create(eventArtistId: string, organizerId: string, dto: CreateTimelineDto) {
    const timeline = await this.prisma.artist_operational_timeline.create({
      data: { ...dto, event_artist_id: eventArtistId, organizer_id: organizerId }
    });

    // Dispara recálculo de alertas automaticamente
    await this.alertCalculator.recalculate(eventArtistId, organizerId);

    return timeline;
  }

  async update(eventArtistId: string, organizerId: string, dto: UpdateTimelineDto) {
    const timeline = await this.prisma.artist_operational_timeline.update({
      where: { event_artist_id_organizer_id: { event_artist_id: eventArtistId, organizer_id: organizerId } },
      data: dto
    });

    // Recalcula sempre que a timeline mudar
    await this.alertCalculator.recalculate(eventArtistId, organizerId);

    return timeline;
  }
}
```

```typescript
// src/modules/logistics/services/transfer.service.ts

async create(eventArtistId: string, organizerId: string, dto: CreateTransferDto) {
  // Calcula planned_eta automaticamente
  const planned_eta_minutes = dto.eta_minutes_peak + dto.safety_buffer_minutes;

  const transfer = await this.prisma.artist_transfer_estimations.create({
    data: { ...dto, planned_eta_minutes, event_artist_id: eventArtistId, organizer_id: organizerId }
  });

  // Recalcula alertas com novo ETA
  await this.alertCalculator.recalculate(eventArtistId, organizerId);

  return transfer;
}
```

---

## Cálculo de `overall_severity` por artista

Usado na listagem consolidada do evento (badge de cor na lista de artistas):

```typescript
function getOverallSeverity(alerts: Alert[]): AlertColor {
  if (!alerts.length) return 'gray';
  const activeAlerts = alerts.filter(a => !a.is_resolved);
  if (!activeAlerts.length) return 'green';
  if (activeAlerts.some(a => a.color_status === 'red')) return 'red';
  if (activeAlerts.some(a => a.color_status === 'orange')) return 'orange';
  if (activeAlerts.some(a => a.color_status === 'yellow')) return 'yellow';
  return 'green';
}
```

---

## Cálculo de `calculated_windows` — retornado na response da timeline

```typescript
function buildCalculatedWindows(timeline: any, transfers: any[], eventArtist: any) {
  const performanceStart = new Date(eventArtist.performance_start_datetime);
  const performanceEnd = new Date(eventArtist.performance_end_datetime);

  return {
    arrival_to_soundcheck_minutes: timeline.soundcheck_datetime
      ? differenceInMinutes(new Date(timeline.soundcheck_datetime), new Date(timeline.arrival_datetime))
      : null,

    arrival_to_performance_minutes: timeline.arrival_datetime
      ? differenceInMinutes(performanceStart, new Date(timeline.arrival_datetime))
      : null,

    performance_end_to_departure_minutes: timeline.venue_departure_datetime
      ? differenceInMinutes(new Date(timeline.venue_departure_datetime), performanceEnd)
      : null,

    departure_to_next_deadline_minutes:
      timeline.venue_departure_datetime && timeline.next_departure_deadline
        ? differenceInMinutes(
            new Date(timeline.next_departure_deadline),
            new Date(timeline.venue_departure_datetime)
          )
        : null
  };
}
```

---

## Cálculos automáticos financeiros — Módulo 2

### Status do payable (recalcular após cada pagamento/estorno)

```typescript
// src/modules/financial/services/payable.service.ts

async recalculatePayableStatus(payableId: string): Promise<void> {
  const payable = await this.prisma.event_payables.findUnique({
    where: { id: payableId }
  });

  let status: PayableStatus;
  if (payable.paid_amount >= payable.amount) {
    status = 'paid';
  } else if (payable.paid_amount > 0) {
    status = 'partial';
  } else if (new Date(payable.due_date) < new Date()) {
    status = 'overdue';
  } else {
    status = 'pending';
  }

  await this.prisma.event_payables.update({
    where: { id: payableId },
    data: {
      status,
      paid_at: status === 'paid' ? new Date() : null,
      remaining_amount: payable.amount - payable.paid_amount
    }
  });
}
```

---

### Job agendado — atualizar payables vencidos

Rodar diariamente (ex: cron `0 6 * * *`) para marcar como `overdue` qualquer payable com `due_date < hoje` e `status = pending`:

```typescript
// src/modules/financial/jobs/overdue-checker.job.ts

@Cron('0 6 * * *')
async markOverduePayables(): Promise<void> {
  await this.prisma.event_payables.updateMany({
    where: {
      status: 'pending',
      due_date: { lt: new Date() }
    },
    data: { status: 'overdue' }
  });
}
```

---

### Consolidado financeiro por artista — query

```typescript
async getCostByArtist(eventId: string, organizerId: string) {
  const eventArtists = await this.prisma.event_artists.findMany({
    where: { event_id: eventId, organizer_id: organizerId },
    include: { artist: true }
  });

  return Promise.all(eventArtists.map(async (ea) => {
    const [logisticsAgg, consumptionAgg] = await Promise.all([
      this.prisma.event_payables.aggregate({
        where: {
          event_id: eventId,
          artist_id: ea.artist_id,
          source_type: 'logistics',
          status: { notIn: ['cancelled'] }
        },
        _sum: { amount: true, paid_amount: true }
      }),
      this.prisma.artist_cards.aggregate({
        where: { event_id: eventId, artist_id: ea.artist_id },
        _sum: { consumed_amount: true }
      })
    ]);

    const logisticsTotal = logisticsAgg._sum.amount ?? 0;
    const consumptionTotal = consumptionAgg._sum.consumed_amount ?? 0;

    return {
      artist_id: ea.artist_id,
      artist_name: ea.artist.stage_name,
      stage: ea.stage,
      performance_date: ea.performance_date,
      cache_amount: ea.cache_amount,
      cache_payment_status: ea.payment_status,
      logistics_committed: logisticsTotal,
      logistics_paid: logisticsAgg._sum.paid_amount ?? 0,
      consumption_total: consumptionTotal,
      grand_total: (ea.cache_amount ?? 0) + logisticsTotal + consumptionTotal
    };
  }));
}
```

---

## Dependências sugeridas

```bash
npm install date-fns           # manipulação de datas
npm install @nestjs/schedule   # cron jobs
npm install ioredis            # TTL de tokens de preview (Redis)
```

---

*Lógica de Alertas e Cálculos Automáticos · EnjoyFun · Node.js / TypeScript*
