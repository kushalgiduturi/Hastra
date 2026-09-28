import { describe, it, expect } from "vitest";
import { dbEncrypt, dbDecrypt, blindIndex, hashPassword, verifyPassword, generateOtp } from "../../functions/_lib/crypto";

const ENC_KEY = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="; // 32 bytes, base64 (test-only)
const INDEX_KEY = "OTA5ODc2NTQzMjEwOTg3NjU0MzIxMDk4NzY1NDMyMTA="; // 32 bytes, base64 (test-only)

describe("column encryption envelope", () => {
  it("round-trips plaintext through the hastra:v1: envelope", async () => {
    const plaintext = "kushal@example.com";
    const encrypted = await dbEncrypt(plaintext, ENC_KEY);
    expect(encrypted.startsWith("hastra:v1:")).toBe(true);
    expect(await dbDecrypt(encrypted, ENC_KEY)).toBe(plaintext);
  });

  it("produces different ciphertext each time (random IV)", async () => {
    const a = await dbEncrypt("same value", ENC_KEY);
    const b = await dbEncrypt("same value", ENC_KEY);
    expect(a).not.toBe(b);
  });

  it("passes non-enveloped values through unchanged on decrypt", async () => {
    expect(await dbDecrypt("plain-legacy-value", ENC_KEY)).toBe("plain-legacy-value");
  });
});

describe("blind index", () => {
  it("is deterministic and case/whitespace-insensitive", async () => {
    const a = await blindIndex("email_bindex", "Kushal@Example.com", INDEX_KEY);
    const b = await blindIndex("email_bindex", " kushal@example.com ", INDEX_KEY);
    expect(a).toBe(b);
  });

  it("rejects fields outside the allow-list", async () => {
    await expect(blindIndex("name_bindex", "x", INDEX_KEY)).rejects.toThrow();
  });
});

describe("password hashing", () => {
  it("verifies a correct password and rejects a wrong one", async () => {
    const hash = await hashPassword("Correct-Horse-1");
    expect(await verifyPassword("Correct-Horse-1", hash)).toBe(true);
    expect(await verifyPassword("wrong-password-1", hash)).toBe(false);
  }, 20000);
});

describe("OTP", () => {
  it("generates a zero-padded 6-digit code", () => {
    for (let i = 0; i < 20; i++) {
      const otp = generateOtp();
      expect(otp).toMatch(/^\d{6}$/);
    }
  });
});
