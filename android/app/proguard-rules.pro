# The app uses no reflection-based serialization, no Room and no Hilt, so the
# default Android rules are almost enough. What is kept below is what R8 would
# otherwise strip from libraries that look up classes by name at runtime.

# OkHttp / Okio
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# ML Kit loads some of its pipeline reflectively.
-keep class com.google.mlkit.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_barcode.** { *; }
-dontwarn com.google.mlkit.**

# Keep the WorkManager worker constructor for its reflective instantiation.
-keep class com.nabilet.checker.sync.SyncWorker { <init>(...); }

# Line numbers make crash reports readable, and the mapping file is uploaded
# next to the APK by the release workflow.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
