import { cva, type VariantProps } from 'class-variance-authority'
import { forwardRef, type ButtonHTMLAttributes } from 'react'
import { cn } from '../lib/utils'

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default: 'bg-brand text-brand-foreground hover:bg-brand/90',
        outline: 'border border-input bg-card hover:bg-muted',
        ghost: 'hover:bg-muted',
        danger: 'bg-danger text-white hover:bg-danger/90',
        link: 'text-brand underline-offset-4 hover:underline',
      },
      size: {
        default: 'h-10 px-4 text-sm',
        sm: 'h-8 px-3 text-xs',
        lg: 'h-12 px-6 text-base',
        /** Kaarigar app: large touch targets. */
        xl: 'h-16 px-6 text-lg [&_svg]:size-6',
        icon: 'size-10',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
)

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
  loading?: boolean
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, loading, disabled, children, ...props }, ref) => (
    <button
      ref={ref}
      className={cn(buttonVariants({ variant, size }), className)}
      disabled={disabled || loading}
      {...props}
    >
      {loading && <span className="size-4 animate-spin rounded-full border-2 border-current border-r-transparent" />}
      {children}
    </button>
  ),
)
Button.displayName = 'Button'

export { buttonVariants }
