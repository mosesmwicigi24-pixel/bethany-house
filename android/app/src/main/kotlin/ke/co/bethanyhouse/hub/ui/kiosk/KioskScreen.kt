package ke.co.bethanyhouse.hub.ui.kiosk

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.Matrix
import android.util.Base64
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageCapture
import androidx.camera.core.ImageCaptureException
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.KioskBoard
import ke.co.bethanyhouse.hub.core.KioskPerson
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import java.io.ByteArrayOutputStream
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlin.coroutines.resume

/**
 * The clock-in kiosk (People C2), the console's /kiosk page on the tablet.
 *
 * Tap your name, type your terminal PIN, the front camera takes one picture,
 * done. Big targets for a 10–12" Samsung tablet, nothing of the Hub to
 * wander into: the activity is pinned (screen pinning asks once when the
 * kiosk starts; a device-owner tablet pins without asking) and the screen
 * stays on. Every rule is the server's: who is on the team, the PIN and its
 * lock-out, the shift gate, the photo size.
 */
@Composable
fun KioskScreen() {
    val env = LocalEnv.current
    val context = LocalContext.current
    val view = LocalView.current
    val scope = rememberCoroutineScope()

    var board by remember { mutableStateOf<KioskBoard?>(null) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var picked by remember { mutableStateOf<KioskPerson?>(null) }
    var pin by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var banner by remember { mutableStateOf<Pair<Boolean, String>?>(null) }
    var bannerJob by remember { mutableStateOf<Job?>(null) }
    var now by remember { mutableStateOf(Instant.now()) }
    var leaving by remember { mutableStateOf(false) }
    val camera = remember { KioskCamera() }

    fun show(ok: Boolean, text: String) {
        banner = ok to text
        bannerJob?.cancel()
        bannerJob = scope.launch { delay(6_000); banner = null }
    }

    suspend fun refresh() {
        try { board = env.kioskApi.board(); loadError = null }
        catch (e: ApiError) { loadError = if (e.isOffline) "No connection to the Hub. Punches need a connection." else e.message }
    }

    // Pinned and awake while this is the kiosk.
    DisposableEffect(Unit) {
        val activity = context as? Activity
        runCatching { activity?.startLockTask() }
        view.keepScreenOn = true
        onDispose {
            view.keepScreenOn = false
            runCatching { activity?.stopLockTask() }
        }
    }
    LaunchedEffect(Unit) { while (true) { refresh(); delay(30_000) } }
    LaunchedEffect(Unit) { while (true) { now = Instant.now(); delay(10_000) } }
    BackHandler(enabled = picked != null) { picked = null; pin = "" }

    fun submit() {
        val p = picked ?: return
        if (pin.length < 4 || busy) return
        busy = true
        scope.launch {
            try {
                val photo = camera.capture()
                val msg = env.kioskApi.punch(p.id, pin, clockIn = !p.isIn, photoDataUrl = photo)
                show(true, msg)
                picked = null; pin = ""
                refresh()
            } catch (e: ApiError) {
                show(false, e.message)
                pin = ""
            } finally { busy = false }
        }
    }

    Surface(color = KioskColors.bg, contentColor = Color.White, modifier = Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize().statusBarsPadding()) {
            Header(board, now)
            banner?.let { (ok, text) ->
                Text(
                    text, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                    modifier = Modifier.padding(horizontal = Space.xl).padding(bottom = Space.m).fillMaxWidth()
                        .background(if (ok) Palette.success else Palette.danger, RoundedCornerShape(16.dp)).padding(Space.l)
                        .semantics { contentDescription = text },
                )
            }
            Box(Modifier.weight(1f).fillMaxWidth()) {
                val p = picked
                if (p == null) PeopleGrid(board, loadError) { picked = it; pin = "" }
                else PinPad(p, pin, busy, camera, onDigit = { if (pin.length < 6) pin += it }, onDelete = { pin = pin.dropLast(1) }, onOk = { submit() }, onBack = { picked = null; pin = "" })
            }
            Row(Modifier.fillMaxWidth().padding(horizontal = Space.xl, vertical = Space.m), verticalAlignment = Alignment.CenterVertically) {
                Text("Bethany House Hub · clock-in kiosk", fontSize = 12.sp, color = KioskColors.dim, modifier = Modifier.weight(1f))
                TextButton(onClick = { leaving = true }) { Text("Leave kiosk mode", color = KioskColors.dim, fontSize = 12.sp) }
            }
        }
    }

    if (leaving) AlertDialog(
        onDismissRequest = { leaving = false },
        title = { Text("Leave kiosk mode?") },
        text = { Text("This tablet stops being the clock-in kiosk and its kiosk key is removed from the Hub. A manager can set it up again from People › Attendance › Kiosks.") },
        confirmButton = { TextButton(onClick = { leaving = false; scope.launch { env.kioskApi.leave() } }) { Text("Yes, leave", color = Palette.danger) } },
        dismissButton = { TextButton(onClick = { leaving = false }) { Text("No") } },
    )
}

private object KioskColors {
    val bg = Color(0xFF111827)
    val tile = Color(0xFF1F2937)
    val tileIn = Color(0xFF047857)
    val key = Color(0xFF1F2937)
    val dim = Color(0xFF9CA3AF)
}

private val nairobiTime: DateTimeFormatter = DateTimeFormatter.ofPattern("HH:mm", Locale.UK).withZone(HubDates.nairobi)
private val nairobiDay: DateTimeFormatter = DateTimeFormatter.ofPattern("EEEE d MMMM", Locale.UK).withZone(HubDates.nairobi)

@Composable
private fun Header(board: KioskBoard?, now: Instant) {
    Row(Modifier.fillMaxWidth().padding(horizontal = Space.xl, vertical = Space.l), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(board?.outlet ?: "Bethany House", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            Text("${board?.kioskName ?: "Kiosk"} · ${board?.inNow ?: 0} clocked in", fontSize = 13.sp, color = KioskColors.dim)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(nairobiTime.format(now), fontSize = 34.sp, fontWeight = FontWeight.Bold)
            Text(nairobiDay.format(now), fontSize = 13.sp, color = KioskColors.dim)
        }
    }
}

@Composable
private fun PeopleGrid(board: KioskBoard?, error: String?, onPick: (KioskPerson) -> Unit) {
    when {
        board == null && error == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Color.White) }
        board == null -> Box(Modifier.fillMaxSize().padding(Space.xl), contentAlignment = Alignment.Center) { Text(error ?: "", color = Color(0xFFFCA5A5), textAlign = TextAlign.Center, fontSize = 18.sp) }
        board.people.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("Nobody is on this outlet's team yet.", color = KioskColors.dim, fontSize = 18.sp) }
        else -> Column {
            if (error != null) Text(error, color = Color(0xFFFCD34D), fontSize = 14.sp, modifier = Modifier.padding(horizontal = Space.xl, vertical = Space.s))
            LazyVerticalGrid(GridCells.Adaptive(220.dp), contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = Space.xl, vertical = Space.s), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(Space.m)) {
                items(board.people, key = { it.id }) { p -> PersonTile(p) { onPick(p) } }
            }
        }
    }
}

@Composable
private fun PersonTile(p: KioskPerson, onClick: () -> Unit) {
    val status = when (p.state) {
        "out" -> "Tap to clock in"
        "break" -> "On break"
        else -> "In since " + (p.since?.let(nairobiTime::format) ?: "")
    } + (p.elsewhere?.let { " · at $it" } ?: "")
    Row(
        Modifier.fillMaxWidth().heightIn(min = 96.dp).background(if (p.isIn) KioskColors.tileIn else KioskColors.tile, RoundedCornerShape(20.dp))
            .clickable(onClick = onClick).padding(Space.l).semantics { contentDescription = "${p.name}, $status" },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(52.dp).background(if (p.isIn) Color(0xFF10B981) else Color(0xFF4B5563), CircleShape), contentAlignment = Alignment.Center) {
            Text(p.initials, fontSize = 18.sp, fontWeight = FontWeight.Bold)
        }
        Spacer(Modifier.width(Space.m))
        Column {
            Text(p.name, fontSize = 17.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(status, fontSize = 13.sp, color = Color(0xFFD1D5DB), maxLines = 2)
        }
    }
}

@Composable
private fun PinPad(
    p: KioskPerson, pin: String, busy: Boolean, camera: KioskCamera,
    onDigit: (String) -> Unit, onDelete: () -> Unit, onOk: () -> Unit, onBack: () -> Unit,
) {
    BoxWithConstraints(Modifier.fillMaxSize().padding(horizontal = Space.xl)) {
        val wide = maxWidth >= 840.dp
        val pad: @Composable () -> Unit = {
            Column(Modifier.widthIn(max = 420.dp), verticalArrangement = Arrangement.spacedBy(Space.m), horizontalAlignment = Alignment.CenterHorizontally) {
                TextButton(onClick = onBack, modifier = Modifier.align(Alignment.Start)) { Text("← Not ${p.name.substringBefore(' ')}? Go back", color = KioskColors.dim) }
                Text(p.name, fontSize = 26.sp, fontWeight = FontWeight.Bold)
                Text(if (p.isIn) "Enter your PIN to clock out." else "Enter your PIN to clock in.", color = Color(0xFFD1D5DB))
                if (!p.hasPin) Text("You have no PIN yet. Set one under Profile › Security on your own phone or computer, then come back.", color = Color(0xFFFCD34D), textAlign = TextAlign.Center, fontSize = 14.sp)
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.padding(vertical = Space.s).semantics { contentDescription = "${pin.length} digits entered" }) {
                    repeat(6) { i -> Box(Modifier.size(16.dp).background(if (i < pin.length) Color.White else if (i < 4) Color(0xFF4B5563) else KioskColors.tile, CircleShape)) }
                }
                listOf(listOf("1", "2", "3"), listOf("4", "5", "6"), listOf("7", "8", "9"), listOf("⌫", "0", "OK")).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        row.forEach { k ->
                            val isOk = k == "OK"
                            val label = when { isOk && busy -> "…"; isOk -> if (p.isIn) "Out" else "In"; else -> k }
                            Box(
                                Modifier.width(104.dp).height(72.dp)
                                    .background(if (isOk && pin.length >= 4) Palette.brand else KioskColors.key, RoundedCornerShape(18.dp))
                                    .clickable(enabled = !busy) { when (k) { "⌫" -> onDelete(); "OK" -> onOk(); else -> onDigit(k) } }
                                    .semantics { contentDescription = when (k) { "⌫" -> "Delete"; "OK" -> if (p.isIn) "Clock out" else "Clock in"; else -> k } },
                                contentAlignment = Alignment.Center,
                            ) { Text(label, fontSize = 26.sp, fontWeight = FontWeight.SemiBold, color = if (isOk && pin.length < 4) Color(0xFF6B7280) else Color.White) }
                        }
                    }
                }
            }
        }
        val cam: @Composable () -> Unit = {
            Column(Modifier.widthIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                KioskCameraPreview(camera, Modifier.fillMaxWidth().aspectRatio(4f / 3f))
                Text("One picture is taken when you press ${if (p.isIn) "Out" else "In"}. Your manager can see it on the punch; it is deleted with the shift trail.", fontSize = 12.sp, color = KioskColors.dim)
            }
        }
        if (wide) Row(Modifier.fillMaxSize(), horizontalArrangement = Arrangement.spacedBy(Space.xxl), verticalAlignment = Alignment.Top) {
            Box(Modifier.weight(1f), contentAlignment = Alignment.TopCenter) { pad() }
            Box(Modifier.weight(1f), contentAlignment = Alignment.TopCenter) { cam() }
        } else Column(Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.l)) { pad(); cam() }
    }
}

/** The front camera while someone is at the PIN pad; one JPEG per punch, or none when there is no camera or no permission. */
private class KioskCamera {
    var capture: ImageCapture? = null

    suspend fun capture(): String? {
        val c = capture ?: return null
        return suspendCancellableCoroutine { cont ->
            c.takePicture(java.util.concurrent.Executors.newSingleThreadExecutor(), object : ImageCapture.OnImageCapturedCallback() {
                override fun onCaptureSuccess(image: ImageProxy) {
                    val url = runCatching { toJpegDataUrl(image) }.getOrNull()
                    image.close()
                    if (cont.isActive) cont.resume(url)
                }
                override fun onError(exception: ImageCaptureException) { if (cont.isActive) cont.resume(null) }
            })
        }
    }

    companion object {
        /** About 480 px wide at quality 70, as the console sends; the server keeps JPEGs up to 450 KB. */
        fun toJpegDataUrl(image: ImageProxy): String? {
            val src = image.toBitmap()
            val m = Matrix().apply { postRotate(image.imageInfo.rotationDegrees.toFloat()) }
            val upright = Bitmap.createBitmap(src, 0, 0, src.width, src.height, m, true)
            val w = 480
            val h = (upright.height * (w.toFloat() / upright.width)).toInt().coerceAtLeast(1)
            val scaled = Bitmap.createScaledBitmap(upright, w, h, true)
            val out = ByteArrayOutputStream()
            scaled.compress(Bitmap.CompressFormat.JPEG, 70, out)
            val bytes = out.toByteArray()
            if (bytes.size > 450_000) return null
            return "data:image/jpeg;base64," + Base64.encodeToString(bytes, Base64.NO_WRAP)
        }
    }
}

@Composable
private fun KioskCameraPreview(camera: KioskCamera, modifier: Modifier) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current
    var granted by remember { mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) }
    var failed by remember { mutableStateOf(false) }
    val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted = it }
    LaunchedEffect(Unit) { if (!granted) ask.launch(Manifest.permission.CAMERA) }
    DisposableEffect(granted) { onDispose { camera.capture = null } }

    Box(modifier.background(Color.Black, RoundedCornerShape(20.dp)), contentAlignment = Alignment.Center) {
        if (!granted || failed) {
            Text("No camera. The punch is recorded without a photo.", color = KioskColors.dim, textAlign = TextAlign.Center, modifier = Modifier.padding(Space.xl))
        } else {
            AndroidView(modifier = Modifier.fillMaxSize(), factory = { ctx ->
                val previewView = PreviewView(ctx)
                val future = ProcessCameraProvider.getInstance(ctx)
                future.addListener({
                    runCatching {
                        val provider = future.get()
                        val preview = Preview.Builder().build().also { it.surfaceProvider = previewView.surfaceProvider }
                        val capture = ImageCapture.Builder().setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY).build()
                        val selector = if (provider.hasCamera(CameraSelector.DEFAULT_FRONT_CAMERA)) CameraSelector.DEFAULT_FRONT_CAMERA else CameraSelector.DEFAULT_BACK_CAMERA
                        provider.unbindAll()
                        provider.bindToLifecycle(lifecycle, selector, preview, capture)
                        camera.capture = capture
                    }.onFailure { failed = true }
                }, ContextCompat.getMainExecutor(ctx))
                previewView
            })
        }
    }
}
