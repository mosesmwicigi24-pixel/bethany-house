package ke.co.bethanyhouse.hub.ui.auth

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color

/**
 * A QR renderer with no third-party dependency: byte mode, error level M,
 * versions 1–10, enough for every `otpauth://` URI the Hub issues. Written
 * from the ISO 18004 tables so the two-step setup screen can show the secret
 * as a code the authenticator app scans.
 */
@Composable
fun QrCode(text: String, modifier: Modifier = Modifier) {
    val matrix = remember(text) { QrEncoder.encode(text) }
    Canvas(modifier.background(Color.White)) {
        if (matrix == null) return@Canvas
        val n = matrix.size
        val cell = size.minDimension / (n + 8)
        val origin = (size.minDimension - cell * n) / 2
        for (y in 0 until n) for (x in 0 until n) if (matrix[y][x]) {
            drawRect(Color.Black, Offset(origin + x * cell, origin + y * cell), Size(cell + 0.5f, cell + 0.5f))
        }
    }
}

object QrEncoder {
    // Data codewords per version at level M, and (EC codewords per block, group1 blocks, group1 words, group2 blocks, group2 words).
    private val capacity = intArrayOf(0, 16, 28, 44, 64, 86, 108, 124, 154, 182, 216)
    private val ecSpec = arrayOf(
        intArrayOf(), intArrayOf(10, 1, 16, 0, 0), intArrayOf(16, 1, 28, 0, 0), intArrayOf(26, 1, 44, 0, 0), intArrayOf(18, 2, 32, 0, 0),
        intArrayOf(24, 2, 43, 0, 0), intArrayOf(16, 4, 27, 0, 0), intArrayOf(18, 4, 31, 0, 0), intArrayOf(22, 2, 38, 2, 39),
        intArrayOf(22, 3, 36, 2, 37), intArrayOf(26, 4, 43, 1, 44),
    )
    private val alignment = arrayOf(intArrayOf(), intArrayOf(), intArrayOf(6, 18), intArrayOf(6, 22), intArrayOf(6, 26), intArrayOf(6, 30), intArrayOf(6, 34), intArrayOf(6, 22, 38), intArrayOf(6, 24, 42), intArrayOf(6, 26, 46), intArrayOf(6, 28, 50))

    private val exp = IntArray(256); private val log = IntArray(256)
    init {
        var x = 1
        for (i in 0 until 255) { exp[i] = x; log[x] = i; x = x shl 1; if (x >= 256) x = x xor 0x11D }
        exp[255] = exp[0]
    }

    fun encode(text: String): Array<BooleanArray>? {
        val bytes = text.toByteArray(Charsets.UTF_8)
        val version = (1..10).firstOrNull { capacity[it] >= bytes.size + (if (it < 10) 2 else 3) } ?: return null
        val total = capacity[version]
        // Byte mode header + data + terminator + pad.
        val bits = StringBuilder()
        bits.append("0100")
        bits.append(bytes.size.toString(2).padStart(if (version < 10) 8 else 16, '0'))
        bytes.forEach { bits.append((it.toInt() and 0xFF).toString(2).padStart(8, '0')) }
        repeat(minOf(4, total * 8 - bits.length)) { bits.append('0') }
        while (bits.length % 8 != 0) bits.append('0')
        val pads = intArrayOf(0xEC, 0x11); var p = 0
        while (bits.length < total * 8) { bits.append(pads[p].toString(2).padStart(8, '0')); p = 1 - p }
        val data = IntArray(total) { bits.substring(it * 8, it * 8 + 8).toInt(2) }

        // Split into blocks, compute EC, interleave.
        val spec = ecSpec[version]
        val ecLen = spec[0]
        val blocks = mutableListOf<IntArray>()
        var off = 0
        repeat(spec[1]) { blocks += data.copyOfRange(off, off + spec[2]); off += spec[2] }
        repeat(spec[3]) { blocks += data.copyOfRange(off, off + spec[4]); off += spec[4] }
        val ecs = blocks.map { reedSolomon(it, ecLen) }
        val out = mutableListOf<Int>()
        val maxLen = blocks.maxOf { it.size }
        for (i in 0 until maxLen) blocks.forEach { if (i < it.size) out += it[i] }
        for (i in 0 until ecLen) ecs.forEach { out += it[i] }

        val n = version * 4 + 17
        val m = Array(n) { BooleanArray(n) }
        val reserved = Array(n) { BooleanArray(n) }
        fun set(x: Int, y: Int, v: Boolean) { m[y][x] = v; reserved[y][x] = true }
        fun finder(x0: Int, y0: Int) {
            for (dy in -1..7) for (dx in -1..7) {
                val x = x0 + dx; val y = y0 + dy
                if (x !in 0 until n || y !in 0 until n) continue
                val on = (dx in 0..6 && dy in 0..6) && (dx == 0 || dx == 6 || dy == 0 || dy == 6 || (dx in 2..4 && dy in 2..4))
                set(x, y, on)
            }
        }
        finder(0, 0); finder(n - 7, 0); finder(0, n - 7)
        val al = alignment[version]
        for (cy in al) for (cx in al) {
            if (reserved[cy][cx]) continue
            for (dy in -2..2) for (dx in -2..2) set(cx + dx, cy + dy, maxOf(kotlin.math.abs(dx), kotlin.math.abs(dy)) != 1)
        }
        for (i in 8 until n - 8) { if (!reserved[6][i]) set(i, 6, i % 2 == 0); if (!reserved[i][6]) set(6, i, i % 2 == 0) }
        set(8, n - 8, true)
        // Reserve format areas.
        for (i in 0..8) { reserved[8][i] = true; reserved[i][8] = true; reserved[8][n - 1 - i] = true; reserved[n - 1 - i][8] = true }
        if (version >= 7) for (i in 0..5) for (j in 0..2) { reserved[i][n - 11 + j] = true; reserved[n - 11 + j][i] = true }

        // Place data.
        var bitIdx = 0
        val totalBits = out.size * 8
        var x = n - 1; var upward = true
        while (x > 0) {
            if (x == 6) x--
            val ys = if (upward) (n - 1 downTo 0) else (0 until n)
            for (y in ys) for (dx in 0..1) {
                val cx = x - dx
                if (reserved[y][cx]) continue
                val bit = if (bitIdx < totalBits) (out[bitIdx / 8] shr (7 - bitIdx % 8)) and 1 == 1 else false
                m[y][cx] = bit; bitIdx++
            }
            x -= 2; upward = !upward
        }

        // Pick the best mask by penalty, apply, write format bits.
        var best: Array<BooleanArray>? = null; var bestScore = Int.MAX_VALUE; var bestMask = 0
        for (mask in 0..7) {
            val c = Array(n) { m[it].copyOf() }
            for (yy in 0 until n) for (xx in 0 until n) if (!reserved[yy][xx] && maskBit(mask, xx, yy)) c[yy][xx] = !c[yy][xx]
            writeFormat(c, n, mask, version)
            val s = penalty(c)
            if (s < bestScore) { bestScore = s; best = c; bestMask = mask }
        }
        return best
    }

    private fun maskBit(mask: Int, x: Int, y: Int): Boolean = when (mask) {
        0 -> (x + y) % 2 == 0; 1 -> y % 2 == 0; 2 -> x % 3 == 0; 3 -> (x + y) % 3 == 0
        4 -> (y / 2 + x / 3) % 2 == 0; 5 -> (x * y) % 2 + (x * y) % 3 == 0
        6 -> ((x * y) % 2 + (x * y) % 3) % 2 == 0; else -> ((x + y) % 2 + (x * y) % 3) % 2 == 0
    }

    private fun writeFormat(c: Array<BooleanArray>, n: Int, mask: Int, version: Int) {
        var f = (0 shl 3) or mask // level M = 00
        var r = f shl 10
        val g = 0x537
        for (i in 14 downTo 10) if ((r shr i) and 1 == 1) r = r xor (g shl (i - 10))
        f = ((f shl 10) or r) xor 0x5412
        for (i in 0..14) {
            val bit = (f shr i) and 1 == 1
            // Around top-left finder.
            if (i < 6) c[8][i] = bit else if (i < 8) c[8][i + 1] = bit else if (i == 8) c[7][8] = bit else c[14 - i][8] = bit
            // Split copy.
            if (i < 8) c[n - 1 - i][8] = bit else c[8][n - 15 + i] = bit
        }
        if (version >= 7) {
            var v = version shl 12
            val vg = 0x1F25
            for (i in 17 downTo 12) if ((v shr i) and 1 == 1) v = v xor (vg shl (i - 12))
            v = v or (version shl 12)
            for (i in 0..17) {
                val bit = (v shr i) and 1 == 1
                c[i / 3][n - 11 + i % 3] = bit
                c[n - 11 + i % 3][i / 3] = bit
            }
        }
    }

    private fun penalty(c: Array<BooleanArray>): Int {
        val n = c.size; var s = 0
        for (y in 0 until n) { var run = 1; for (x in 1 until n) { if (c[y][x] == c[y][x - 1]) { run++; if (run == 5) s += 3 else if (run > 5) s++ } else run = 1 } }
        for (x in 0 until n) { var run = 1; for (y in 1 until n) { if (c[y][x] == c[y - 1][x]) { run++; if (run == 5) s += 3 else if (run > 5) s++ } else run = 1 } }
        for (y in 0 until n - 1) for (x in 0 until n - 1) if (c[y][x] == c[y][x + 1] && c[y][x] == c[y + 1][x] && c[y][x] == c[y + 1][x + 1]) s += 3
        val dark = c.sumOf { row -> row.count { it } }
        val pct = dark * 100 / (n * n)
        s += (minOf(kotlin.math.abs(pct - 50) / 5, kotlin.math.abs(pct - 45) / 5)) * 10
        return s
    }

    private fun reedSolomon(data: IntArray, ecLen: Int): IntArray {
        var gen = intArrayOf(1)
        for (i in 0 until ecLen) {
            val next = IntArray(gen.size + 1)
            for (j in gen.indices) { next[j] = next[j] xor gen[j]; next[j + 1] = next[j + 1] xor mul(gen[j], exp[i]) }
            gen = next
        }
        val msg = IntArray(data.size + ecLen); data.copyInto(msg)
        for (i in data.indices) {
            val coef = msg[i]
            if (coef != 0) for (j in gen.indices) msg[i + j] = msg[i + j] xor mul(gen[j], coef)
        }
        return msg.copyOfRange(data.size, msg.size)
    }

    private fun mul(a: Int, b: Int): Int = if (a == 0 || b == 0) 0 else exp[(log[a] + log[b]) % 255]
}
