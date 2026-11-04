import { describe, expect, it } from 'vitest'
import type { AuditLogDto } from '@/api/types'
import { CSV_ROW_CAP, auditCsv } from '@/lib/auditCsv'
import { csvCell, toCsv } from '@/lib/csv'
import { auditRow } from './../fixtures/staff'

describe('csvCell', () => {
  it('prefixes a cell a spreadsheet would run as a formula', () => {
    expect(csvCell('=cmd|calc')).toBe("'=cmd|calc")
    expect(csvCell('+1+1')).toBe("'+1+1")
    expect(csvCell('-2+3')).toBe("'-2+3")
    expect(csvCell('@SUM(A1)')).toBe("'@SUM(A1)")
  })

  it('looks at the first character that is not blank', () => {
    expect(csvCell('  =1+1')).toBe("'  =1+1")
    expect(csvCell('\t=1+1')).toBe("'\t=1+1")
    expect(csvCell(' @x')).toBe("' @x")
  })

  it('leaves ordinary text and numbers alone', () => {
    expect(csvCell('approved')).toBe('approved')
    expect(csvCell('a=b')).toBe('a=b')
    expect(csvCell(12)).toBe('12')
    expect(csvCell(null)).toBe('')
    expect(csvCell(undefined)).toBe('')
  })

  it('quotes per RFC 4180 and doubles quotes', () => {
    expect(csvCell('a,b')).toBe('"a,b"')
    expect(csvCell('say "hi"')).toBe('"say ""hi"""')
    expect(csvCell('two\nlines')).toBe('"two\nlines"')
  })

  it('writes objects as JSON text, guarded like any other text', () => {
    expect(csvCell({ a: 1 })).toBe('"{""a"":1}"')
  })
})

describe('toCsv', () => {
  it('joins rows with CRLF', () => {
    expect(toCsv([['a', 'b'], ['1', '=2']])).toBe("a,b\r\n1,'=2")
  })
})

describe('auditCsv', () => {
  it('has a header and one line per entry, with meta as JSON text', () => {
    const csv = auditCsv([auditRow({ id: 'x1', meta: { decision: 'approved' }, actor: { id: 'a', handle: 'kenji' } })])
    const [head, line] = csv.split('\r\n')
    expect(head).toBe('id,timestamp_utc,actor_handle,action,subject_type,subject_id,meta')
    expect(line).toContain('x1,')
    expect(line).toContain(',kenji,product.reviewed,product,')
    expect(line).toContain('"{""decision"":""approved""}"')
  })

  it('guards a hostile subject id and handle', () => {
    const csv = auditCsv([auditRow({ subject_id: '=HYPERLINK("http://evil")', actor: { id: 'a', handle: '+evil' } })])
    expect(csv).toContain(`"'=HYPERLINK(""http://evil"")"`)
    expect(csv).toContain(",'+evil,")
  })

  it('keeps at most the capped number of rows', () => {
    const many: AuditLogDto[] = Array.from({ length: CSV_ROW_CAP + 5 }, (_, i) => auditRow({ id: `id${i}` }))
    expect(auditCsv(many).split('\r\n')).toHaveLength(CSV_ROW_CAP + 1)
  })
})
