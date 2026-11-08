export interface StaffUser {
  id: number
  name: string
  email: string
  phone: string | null
  roles: string[]
  permissions: string[]
}

export interface Karat {
  id: number
  label: string
  purity_pct: string
  sort: number
  is_active: boolean
}

export interface WastageRule {
  id: number
  process_id: number
  karat_id: number
  allowed_wastage_pct: string
}

export interface Process {
  id: number
  name: string
  sequence: number
  default_wastage_pct: string
  is_active: boolean
  wastage_rules: WastageRule[]
}

export interface KaarigarAppUser {
  id: number
  name: string
  phone: string
  is_master: boolean
  language: string
  is_active: boolean
}

export interface Kaarigar {
  id: number
  name: string
  phone: string | null
  type: 'in_house' | 'outside'
  address: string | null
  is_active: boolean
  app_users_count?: number
  app_users?: KaarigarAppUser[]
}

export interface Category {
  id: number
  name: string
  parent_id: number | null
}

export interface DesignImage {
  id: number
  url: string
  thumb_url: string | null
}

export interface Design {
  id: number
  code: string
  name: string
  category_id: number | null
  category?: { id: number; name: string } | null
  approx_weight_min: string | null
  approx_weight_max: string | null
  sizes: string[] | null
  making_charge: string | null
  making_type: 'per_gram' | 'per_piece' | 'percent' | null
  is_published: boolean
  karats?: { id: number; label: string }[]
  images?: DesignImage[]
}

export interface Retailer {
  id: number
  shop_name: string
  contact_name: string | null
  gstin: string | null
  city: string | null
  phone: string | null
  credit_limit: string
  metal_account_enabled: boolean
  status: 'pending' | 'active' | 'suspended' | 'rejected'
  users_count?: number
}

export interface GoldRate {
  id: number
  date: string
  karat_id: number
  rate_per_g: string
  karat: { id: number; label: string; purity_pct: string }
}

export interface Role {
  id: number
  name: string
  permissions: string[]
}

export interface StaffListUser {
  id: number
  name: string
  email: string
  phone: string | null
  is_active: boolean
  roles: string[]
}
