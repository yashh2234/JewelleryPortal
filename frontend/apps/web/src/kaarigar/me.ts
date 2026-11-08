export interface KaarigarMe {
  id: number
  name: string
  phone: string
  language: 'hi' | 'en' | 'bn' | 'gu'
  is_master: boolean
  kaarigar: { id: number; name: string; type: string }
}

export const meKey = ['kaarigar', 'me'] as const
