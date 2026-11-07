// Watermelon UI registry item "utils" (registry.watermelon.sh/r/utils.json).
import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
