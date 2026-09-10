import { cn } from "cn"
import { Checkbox as CheckboxPrimitive } from "radix-ui"
import { Check } from "lucide-react"
import * as React from "react"

function Checkbox({
  className,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        "peer size-4.5 shrink-0 rounded-[0.25rem] border border-control bg-surface",
        "transition-colors duration-(--dur-micro) ease-out",
        "data-[state=checked]:border-ink data-[state=checked]:bg-ink data-[state=checked]:text-ink-ink",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info",
        "disabled:cursor-not-allowed disabled:opacity-55",
        className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="grid place-items-center text-current"
      >
        <Check className="size-3.5" strokeWidth={3} aria-hidden />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }
