package ke.co.bethanyhouse.hub.ui.components

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.hub
import java.util.concurrent.Executors

/**
 * Camera barcode / QR scanner (CameraX + ML Kit) as a bottom sheet. The
 * first code read is handed to `onScanned` and the sheet closes; a code is
 * only ever a lookup key (serial, SKU, order number), never a value.
 */
@Composable
fun BarcodeScannerSheet(title: String = "Scan a code", onDismiss: () -> Unit, onScanned: (String) -> Unit) {
    val context = LocalContext.current
    var granted by remember { mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) }
    val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted = it }
    LaunchedEffect(Unit) { if (!granted) ask.launch(Manifest.permission.CAMERA) }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Text(title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(horizontal = Space.l))
        Box(Modifier.fillMaxWidth().height(360.dp).padding(Space.l), contentAlignment = Alignment.Center) {
            if (granted) CameraPreview { code -> onScanned(code); onDismiss() }
            else {
                Text("Camera access is needed to scan.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.hub.textSecondary)
                Button(onClick = { ask.launch(Manifest.permission.CAMERA) }, modifier = Modifier.align(Alignment.BottomCenter)) { Text("Allow camera") }
            }
        }
    }
}

@Composable
private fun CameraPreview(onCode: (String) -> Unit) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current
    val executor = remember { Executors.newSingleThreadExecutor() }
    val scanner = remember { BarcodeScanning.getClient(BarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_ALL_FORMATS).build()) }
    var delivered by remember { mutableStateOf(false) }
    DisposableEffect(Unit) { onDispose { executor.shutdown(); scanner.close() } }
    val onCodeSafe: (String) -> Unit = { code -> if (!delivered) { delivered = true; onCode(code) } }
    AndroidView(modifier = Modifier.fillMaxSize(), factory = { ctx ->
        val view = PreviewView(ctx)
        val future = ProcessCameraProvider.getInstance(ctx)
        future.addListener({
            val provider = future.get()
            val preview = Preview.Builder().build().also { it.surfaceProvider = view.surfaceProvider }
            val analysis = ImageAnalysis.Builder().setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build()
            analysis.setAnalyzer(executor) { proxy -> analyse(scanner, proxy) { code -> view.post { onCodeSafe(code) } } }
            provider.unbindAll()
            provider.bindToLifecycle(lifecycle, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis)
        }, ContextCompat.getMainExecutor(ctx))
        view
    })

}

@androidx.annotation.OptIn(androidx.camera.core.ExperimentalGetImage::class)
private fun analyse(scanner: com.google.mlkit.vision.barcode.BarcodeScanner, proxy: ImageProxy, onCode: (String) -> Unit) {
    val media = proxy.image ?: run { proxy.close(); return }
    val image = InputImage.fromMediaImage(media, proxy.imageInfo.rotationDegrees)
    scanner.process(image)
        .addOnSuccessListener { codes -> codes.firstNotNullOfOrNull { it.rawValue }?.let(onCode) }
        .addOnCompleteListener { proxy.close() }
}
