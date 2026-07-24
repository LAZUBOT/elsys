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
- تفعيل Authentication (حتى لو تم استخدام Anonymous Auth كبديل)

## التشغيل
1. افتح المشروع من المتصفح.
2. سجّل الدخول إلى النظام.
3. عند اختيار منطقة، سيتم:
   - تحميل البيانات من Firestore إذا كانت موجودة
   - أو مزامنتها إلى Firestore عند توفر الاتصال

## ملاحظات
- إذا تعذر الاتصال بـ Firestore، يبقى التطبيق يعمل محليًا دون انهيار.
- يتم حفظ نسخة محلية من الملاحظات في `localStorage`.
