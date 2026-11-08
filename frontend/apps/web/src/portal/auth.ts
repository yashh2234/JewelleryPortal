export interface RetailerMe {
  id: number
  name: string
  email: string
  phone: string | null
  role: 'owner' | 'staff'
  retailer: { id: number; shop_name: string; city: string | null; status: string; metal_account_enabled: boolean }
}
