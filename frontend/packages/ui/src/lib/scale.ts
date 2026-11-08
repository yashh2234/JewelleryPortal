/**
 * Reads the weight from an electronic scale over USB/serial (Web Serial API,
 * Chrome/Edge on desktop). Most jewellery scales stream lines like
 * "ST,GS,+  12.345 g" or "  12.345g"; we take the last number on the most recent line.
 * Manual entry always remains available.
 */

interface SerialPortLike {
  open(options: { baudRate: number }): Promise<void>
  readable: ReadableStream<Uint8Array> | null
  close(): Promise<void>
}

interface SerialLike {
  requestPort(): Promise<SerialPortLike>
  getPorts(): Promise<SerialPortLike[]>
}

const serial = (): SerialLike | undefined => (navigator as Navigator & { serial?: SerialLike }).serial

export const scaleSupported = () => !!serial()

let port: SerialPortLike | null = null

export function parseScaleLine(line: string): string | null {
  const matches = line.match(/[-+]?\s*\d+(?:\.\d+)?/g)
  if (!matches) return null
  const value = matches[matches.length - 1].replace(/[\s+]/g, '')
  const n = Number(value)
  if (!Number.isFinite(n) || n < 0) return null
  return n.toFixed(3)
}

/** Returns the weight in grams as a 3-decimal string. Prompts for the port the first time. */
export async function readScale(baudRate = 9600, timeoutMs = 3000): Promise<string> {
  const s = serial()
  if (!s) throw new Error('This browser cannot read a scale. Use Chrome on the store PC, or type the weight.')

  if (!port) {
    const known = await s.getPorts()
    port = known[0] ?? (await s.requestPort())
    await port.open({ baudRate })
  }

  const reader = port.readable!.getReader()
  const decoder = new TextDecoder()
  let buffer = ''
  let last: string | null = null
  const deadline = Date.now() + timeoutMs

  try {
    while (Date.now() < deadline) {
      const timer = new Promise<{ done: true; value?: undefined }>((r) => setTimeout(() => r({ done: true }), deadline - Date.now()))
      const { value, done } = await Promise.race([reader.read(), timer])
      if (done || !value) break
      buffer += decoder.decode(value, { stream: true })
      const lines = buffer.split(/\r?\n/)
      buffer = lines.pop() ?? ''
      for (const line of lines) {
        const w = parseScaleLine(line)
        if (w !== null) last = w
      }
      if (last !== null && lines.length > 1) break
    }
  } finally {
    reader.releaseLock()
  }

  if (last === null) throw new Error('No reading from the scale. Check the cable and that the scale is sending data.')
  return last
}
