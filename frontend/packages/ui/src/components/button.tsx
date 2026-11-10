import { cva, type VariantProps } from 'class-variance-authority'
import { forwardRef, type ButtonHTMLAttributes } from 'react'
import { cn } from '../lib/utils'

const buttonVariants = cva(
  'inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg font-medium transition-all duration-150 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default:
          'bg-brand text-brand-foreground shadow-[0_1px_2px_rgb(0_0_0/0.12),inset_0_1px_0_rgb(255_255_255/0.14)] hover:brightness-110 hover:shadow-raised',
        outline: 'border border-input bg-card shadow-xs hover:border-foreground/20 hover:bg-muted',
        ghost: 'text-foreground/80 hover:bg-muted hover:text-foreground',
        danger: 'bg-danger text-white shadow-xs hover:brightness-110 dark:text-[#2a0b0c]',
        link: 'text-brand underline-offset-4 hover:underline active:scale-100',
      },
      size: {
        default: 'h-10 px-4 text-sm',
        sm: 'h-8 rounded-md px-3 text-xs',
        lg: 'h-12 px-6 text-[0.9375rem]',
        /** Kaarigar app: large touch targets. */
        xl: 'h-16 rounded-xl px-6 text-lg [&_svg]:size-6',
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
