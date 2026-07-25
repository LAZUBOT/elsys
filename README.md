# Firestore Sync for FTTH Subscribers

هذا المشروع يحتوي الآن على طبقة اتصال مع Firebase Firestore مدمجة مع صفحة التشغيل الحالية.

## ما الذي يتم مزامنته
- بيانات المشتركين في collection `customers`
- ملاحظات المستخدم في collection `notes/{agentId}/customerNotes/{customerId}`
- بيانات العامل في collection `agents`

## الملفات المهمة
- [index.html](index.html): الصفحة الرئيسية التي تستخدم منطق Firestore
- [firebase-sync.js](firebase-sync.js): منطق Firebase و Firestore المنفصل

## المتطلبات
- اتصال بالإنترنت
- مشروع Firebase فعال
- تمكين Firestore في Firebase Console
- تفعيل Authentication
  - إذا كانت قواعد Firestore تعتمد على `request.auth != null`، فعليك تمكين Anonymous Authentication أو إعداد مصادقة بديلة

## التشغيل
1. افتح المشروع من المتصفح.
2. سجّل الدخول إلى النظام.
3. عند اختيار منطقة، سيتم:
   - تحميل البيانات من Firestore إذا كانت موجودة
   - أو مزامنتها إلى Firestore عند توفر الاتصال

## ملاحظات
- إذا تعذر الاتصال بـ Firestore، يبقى التطبيق يعمل محليًا دون انهيار.
- يتم حفظ نسخة محلية من الملاحظات في `localStorage`.

## قواعد Firestore المطلوبة
إذا ظهرت رسالة أن Firestore يرفض الكتابة، أضف هذه القواعد في Firebase Console > Firestore Database > Rules:

يمكنك نسخها من الملف [firestore-rules.txt](firestore-rules.txt).

```js
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    match /{document=**} {
      allow read, write: if request.auth != null;
    }
  }
}
```

إذا أردت وضعًا أكثر أمانًا، استبدل السطر الأخير بـ:

```js
allow read, write: if request.auth != null && request.auth.uid == 'your-uid';
```
