export type UiReplacements = Record<string, string | number | boolean | null | undefined>;

declare global {
  interface Window {
    MC_I18N?: Record<string, string>;
  }
}

export function t(key: string, replace: UiReplacements = {}): string {
  let value = String(window.MC_I18N?.[key] ?? key);
  for (const [name, replacement] of Object.entries(replace)) {
    value = value.replaceAll(`%${name}%`, String(replacement ?? ''));
  }
  return value;
}
