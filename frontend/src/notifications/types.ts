export type NotificationType = 'booking' | 'billing' | 'announcement' | 'system'

export type SourceRole = 'owner' | 'coach' | 'staff' | 'member'

export interface NotificationDto {
  id: string
  title: string
  body: string
  type: NotificationType
  /** Who the notification is "from" — null for system-scheduled reminders with no human actor. */
  sourceRole: SourceRole | null
  read: boolean
  createdAt: string
}

export type AnnouncementAudience = 'gym_wide' | 'own_clients'
