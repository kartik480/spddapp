// File generated using Firebase project configuration
// Project: superdaily-2ae6f

import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;
import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, kIsWeb, TargetPlatform;

/// Default [FirebaseOptions] for use with your Firebase apps.
///
/// Example:
/// ```dart
/// import 'firebase_options.dart';
/// // ...
/// await Firebase.initializeApp(
///   options: DefaultFirebaseOptions.currentPlatform,
/// );
/// ```
class DefaultFirebaseOptions {
  static FirebaseOptions get currentPlatform {
    if (kIsWeb) {
      return web;
    }
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return android;
      case TargetPlatform.iOS:
        return ios;
      case TargetPlatform.macOS:
        return macos;
      case TargetPlatform.windows:
        throw UnsupportedError(
          'DefaultFirebaseOptions have not been configured for windows - '
          'you can reconfigure this by running the FlutterFire CLI again.',
        );
      case TargetPlatform.linux:
        throw UnsupportedError(
          'DefaultFirebaseOptions have not been configured for linux - '
          'you can reconfigure this by running the FlutterFire CLI again.',
        );
      default:
        throw UnsupportedError(
          'DefaultFirebaseOptions are not supported for this platform.',
        );
    }
  }

  static const FirebaseOptions web = FirebaseOptions(
    apiKey: 'AIzaSyDJY4rF98VKdOH-xpRTuCNekxprbOlyxlQ',
    // TODO: Get your web app ID from Firebase Console:
    // 1. Go to https://console.firebase.google.com/
    // 2. Select project: superdaily-2ae6f
    // 3. Go to Project Settings > Your apps
    // 4. If no web app exists, click "Add app" and select Web
    // 5. Copy the App ID (format: 1:106388971312:web:xxxxx)
    appId: '1:106388971312:web:YOUR_WEB_APP_ID',
    messagingSenderId: '106388971312',
    projectId: 'superdaily-2ae6f',
    authDomain: 'superdaily-2ae6f.firebaseapp.com',
    storageBucket: 'superdaily-2ae6f.firebasestorage.app',
  );

  static const FirebaseOptions android = FirebaseOptions(
    apiKey: 'AIzaSyDJY4rF98VKdOH-xpRTuCNekxprbOlyxlQ',
    appId: '1:106388971312:android:00954caf01b2d948f532f5',
    messagingSenderId: '106388971312',
    projectId: 'superdaily-2ae6f',
    storageBucket: 'superdaily-2ae6f.firebasestorage.app',
  );

  static const FirebaseOptions ios = FirebaseOptions(
    apiKey: 'AIzaSyDJY4rF98VKdOH-xpRTuCNekxprbOlyxlQ',
    appId: '1:106388971312:ios:YOUR_IOS_APP_ID',
    messagingSenderId: '106388971312',
    projectId: 'superdaily-2ae6f',
    storageBucket: 'superdaily-2ae6f.firebasestorage.app',
    iosBundleId: 'com.spdapp.spdapp',
  );

  static const FirebaseOptions macos = FirebaseOptions(
    apiKey: 'AIzaSyDJY4rF98VKdOH-xpRTuCNekxprbOlyxlQ',
    appId: '1:106388971312:macos:YOUR_MACOS_APP_ID',
    messagingSenderId: '106388971312',
    projectId: 'superdaily-2ae6f',
    storageBucket: 'superdaily-2ae6f.firebasestorage.app',
    iosBundleId: 'com.spdapp.spdapp',
  );
}

