/** RFC 4180-style UTF-8 CSV. Text cannot become an Excel formula on open. */
export function serializeCsv(headers: string[], rows: (string | number)[][]): string {
  const safe = (v: string | number) => typeof v === 'string' && /^[=+\-@\t\r]/.test(v) ? `'${v}` : v;
  return '\ufeff' + [headers, ...rows].map(row => row.map(v => `"${String(safe(v)).replaceAll('"', '""')}"`).join(',')).join('\r\n');
}
