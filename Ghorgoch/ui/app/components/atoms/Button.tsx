"use client";

import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from "react";

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "outline" | "ghost";
  size?: "sm" | "md" | "lg";
  fullWidth?: boolean;
  loading?: boolean;
  loadingLabel?: ReactNode;
  startIcon?: ReactNode;
  endIcon?: ReactNode;
};

const variants = {
  primary: "border-cocoa bg-cocoa text-ivory enabled:hover:opacity-90",
  outline: "border-cocoa/25 bg-ivory text-cocoa enabled:hover:bg-cocoa/5",
  ghost: "border-transparent text-cocoa enabled:hover:bg-cocoa/5",
};

const sizes = {
  sm: "min-h-11 gap-1.5 px-3 py-2 text-xs",
  md: "min-h-11 gap-2 px-4 py-2.5 text-sm",
  lg: "min-h-12 gap-2.5 px-6 py-3 text-base",
};

const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button({
  variant = "primary", size = "md", fullWidth = false, loading = false,
  loadingLabel, startIcon, endIcon, className = "", children, disabled,
  type = "button", ...props
}, ref) {
  return <button
    {...props}
    ref={ref}
    type={type}
    disabled={disabled || loading}
    aria-busy={loading || undefined}
    className={`inline-flex items-center justify-center rounded-card border font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cocoa disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none ${variants[variant]} ${sizes[size]} ${fullWidth ? "w-full" : ""} ${className}`}
  >
    {loading ? <span className="size-4 shrink-0 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none" aria-hidden="true" /> : startIcon && <span className="shrink-0" aria-hidden="true">{startIcon}</span>}
    <span>{loading && loadingLabel ? loadingLabel : children}</span>
    {endIcon && <span className="shrink-0" aria-hidden="true">{endIcon}</span>}
  </button>;
});

export default Button;
