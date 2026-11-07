// Watermelon UI "Input OTP 10" (components/input-otp-10): six slots in two
// groups of three with a dot between them. Made controlled and digits-only
// for the sign-in and reset flows; the label is passed in.
import { useId } from "react";
import { REGEXP_ONLY_DIGITS } from "input-otp";

import { InputOTP, InputOTPGroup, InputOTPSlot } from "./input-otp";
import { Label } from "./label";

interface InputOtp10Props {
  label: string;
  value: string;
  onChange: (value: string) => void;
  autoFocus?: boolean;
}

const InputOtp10 = ({ label, value, onChange, autoFocus }: InputOtp10Props) => {
  const id = useId();
  return (
    <div className="space-y-3">
      <Label htmlFor={id}>{label}</Label>
      <InputOTP
        id={id}
        maxLength={6}
        value={value}
        onChange={onChange}
        pattern={REGEXP_ONLY_DIGITS}
        inputMode="numeric"
        autoComplete="one-time-code"
        autoFocus={autoFocus}
        required
      >
        <InputOTPGroup>
          <InputOTPSlot index={0} />
          <InputOTPSlot index={1} />
          <InputOTPSlot index={2} />
        </InputOTPGroup>
        <div role="separator" className="flex items-center justify-center px-1">
          <span className="h-1.5 w-1.5 rounded-full bg-[var(--sp-accent)]" />
        </div>
        <InputOTPGroup>
          <InputOTPSlot index={3} />
          <InputOTPSlot index={4} />
          <InputOTPSlot index={5} />
        </InputOTPGroup>
      </InputOTP>
    </div>
  );
};

export default InputOtp10;
