/**
 * Shared class strings for the auth-card forms ("The Position" visual
 * direction: hard ink borders, zero radius, sun focus shadow). Used by the
 * pages under resources/js/pages/auth and the passkey button.
 */
export const authLabel =
    'font-plex-mono text-[10.5px] font-semibold tracking-[.05em] uppercase text-stone';

export const authInput =
    'h-auto rounded-none border-[1.5px] border-ink bg-white px-[13px] py-[11px] text-ink shadow-none placeholder:text-ash focus-visible:border-ink focus-visible:ring-0 focus-visible:shadow-[3px_3px_0_var(--color-sun)]';

export const authSubmitButton =
    'h-auto w-full rounded-none bg-ink py-[13px] text-sm font-bold text-paper shadow-none transition-colors duration-[180ms] hover:bg-rust';

export const authOutlineButton =
    'h-auto w-full rounded-none border-[1.5px] border-ink bg-white py-[11px] text-[13px] font-semibold text-ink shadow-none transition-colors duration-[180ms] hover:bg-paper hover:text-ink';

export const authError = 'text-[12px] text-rust dark:text-rust';

export const authSuccessBox =
    'border border-moss bg-[#e9efe6] px-4 py-3 text-[12.5px] text-[#24513c]';
