import type { TicketCard } from './types'

/** Only issued tickets with a known future start can be the next outing. */
export function upcomingTickets(tickets: TicketCard[], now = Date.now()): TicketCard[] {
  return tickets.filter(ticket => ticket.status === 'issued' && Date.parse(ticket.sessionAt) >= now)
    .sort((a, b) => Date.parse(a.sessionAt) - Date.parse(b.sessionAt))
}
