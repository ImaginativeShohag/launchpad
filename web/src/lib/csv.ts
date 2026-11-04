/** A spreadsheet runs a cell as a formula when its first visible character is one of these. */
const FORMULA_START = /^[\s\u0000-\u001f ​﻿]*[=+\-@]/

/**
 * One CSV cell as text. A cell that could be read as a formula (first non-blank character `=`, `+`, `-` or `@`) gets a
 * leading apostrophe, so Excel and Sheets show it instead of running it. RFC 4180 quoting: a cell with a comma, a quote, a
 * line break or an edge space is quoted and its quotes doubled.
 */
export function csvCell(value: unknown): string {
  let text = value === null || value === undefined ? '' : typeof value === 'string' ? value : typeof value === 'object' ? JSON.stringify(value) : String(value)
  if (FORMULA_START.test(text)) text = `'${text}`
  return /[",\r\n]|^\s|\s$/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

/** Rows to CRLF-separated CSV text (RFC 4180). The first row is the header. */
export function toCsv(rows: unknown[][]): string {
  return rows.map((r) => r.map(csvCell).join(',')).join('\r\n')
}
