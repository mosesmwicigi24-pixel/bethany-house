# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.AnnotationsKt
-keepclassmembers class kotlinx.serialization.json.** { *** Companion; }
-keepclasseswithmembers class kotlinx.serialization.json.** { kotlinx.serialization.KSerializer serializer(...); }
-keep,includedescriptorclasses class ke.co.bethanyhouse.hub.**$$serializer { *; }
-keepclassmembers class ke.co.bethanyhouse.hub.** { *** Companion; }
-keepclasseswithmembers class ke.co.bethanyhouse.hub.** { kotlinx.serialization.KSerializer serializer(...); }
# OkHttp
-dontwarn okhttp3.**
-dontwarn org.conscrypt.**
