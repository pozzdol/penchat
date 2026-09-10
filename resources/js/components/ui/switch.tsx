import { cn } from "cn"
import { Switch as SwitchPrimitive } from "radix-ui"
import * as React from "react"

function Switch({
  className,
  ...props
}: React.ComponentProps<typeof SwitchPrimitive.Root>) {
  return (
    <SwitchPrimitive.Root
      data-slot="switch"
      className={cn(
        "peer inline-flex h-6 w-10 shrink-0 items-center rounded-full border border-transparent",
        "transition-colors duration-(--dur-micro) ease-out",
        "data-[state=checked]:bg-fill data-[state=unchecked]:bg-control",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info",
        "disabled:cursor-not-allowed disabled:opacity-55",
        className
      )}
      {...props}
    >
      <SwitchPrimitive.Thumb
        data-slot="switch-thumb"
        className={cn(
          "pointer-events-none block size-5 rounded-full bg-surface shadow-soft-sm ring-0",
          "transition-transform duration-(--dur-micro) ease-out",
          "data-[state=checked]:translate-x-[1.125rem] data-[state=unchecked]:translate-x-0.5"
        )}
      />
    </SwitchPrimitive.Root>
  )
}

export { Switch }
