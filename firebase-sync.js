const firebaseSync = (() => {
  let app = null;
  let auth = null;
  let firestore = null;
  let firebaseReady = false;
  let currentAgentId = null;
  let currentAgentName = '';
  let currentZoneId = null;
  let firestoreCustomerListener = null;
  let noteSaveTimers = {};
  let userNotes = {};
  let fetchedCustomersData = [];
  let onDataUpdate = null;

  const explicitFirebaseConfig = {
    apiKey: 'AIzaSyC_X_XzwaBDY6TUNxE2hJzJHtClyXnA7ec',
    authDomain: 'wzftth.firebaseapp.com',
    databaseURL: 'https://wzftth-default-rtdb.firebaseio.com',
    projectId: 'wzftth',
    storageBucket: 'wzftth.firebasestorage.app',
    messagingSenderId: '409016821583',
    appId: '1:409016821583:web:1e90e4266b21744b95c73a',
    measurementId: 'G-TPM5E3M91B'
  };

  function setContext(context) {
    currentAgentId = context.agentId || null;
    currentAgentName = context.agentName || '';
    currentZoneId = context.zoneId || null;
  }

  function registerDataCallback(callback) {
    onDataUpdate = callback;
  }

  function getState() {
    return {
      firebaseReady,
      currentAgentId,
      currentAgentName,
      currentZoneId,
      userNotes
    };
  }

  function getFirebaseHandles() {
    return { app, auth, firestore };
  }

  async function initFirebase() {
    if (firebaseReady && app && firestore) return true;

    try {
      if (!app) {
        const { initializeApp } = await import('https://www.gstatic.com/firebasejs/11.6.1/firebase-app.js');
        app = initializeApp(explicitFirebaseConfig);
      }

      const { getAuth, signInAnonymously, signInWithCustomToken, onAuthStateChanged } = await import('https://www.gstatic.com/firebasejs/11.6.1/firebase-auth.js');
      const { getFirestore } = await import('https://www.gstatic.com/firebasejs/11.6.1/firebase-firestore.js');

      auth = getAuth(app);
      firestore = getFirestore(app);

      firebaseReady = Boolean(app && firestore);

      await new Promise((resolve) => {
        const unsubscribe = onAuthStateChanged(auth, async (user) => {
          unsubscribe();
          if (user) {
            firebaseReady = true;
            resolve();
            return;
          }

          try {
            let signedIn = false;
            if (typeof window.__initial_auth_token !== 'undefined' && window.__initial_auth_token) {
              try {
                await signInWithCustomToken(auth, window.__initial_auth_token);
                signedIn = true;
              } catch (err) {
                console.warn('Custom token auth failed, continuing with Firestore:', err);
              }
            }
            if (!signedIn) {
              try {
                await signInAnonymously(auth);
                signedIn = true;
              } catch (err) {
                console.warn('Anonymous auth unavailable, continuing with Firestore:', err);
              }
            }
            firebaseReady = true;
            resolve();
          } catch (err) {
            firebaseReady = true;
            resolve();
          }
        }, () => resolve());
      });

      return true;
    } catch (err) {
      firebaseReady = Boolean(app && firestore);
      console.warn('Firebase init warning:', err);
      return Boolean(app && firestore);
    }
  }

  function stopCustomerListener() {
    if (firestoreCustomerListener) {
      firestoreCustomerListener();
      firestoreCustomerListener = null;
    }
  }

  async function loadCustomersFromFirestore(zoneId) {
    if (!firestore || !firebaseReady || !zoneId) return null;

    try {
      const { collection, onSnapshot, getDocs } = await import('https://www.gstatic.com/firebasejs/11.6.1/firebase-firestore.js');
      stopCustomerListener();

      const customersRef = collection(firestore, 'customers');
      firestoreCustomerListener = onSnapshot(customersRef, (snapshot) => {
        const items = [];
        snapshot.forEach((docItem) => {
          const data = docItem.data() || {};
          if (String(data.agentId) !== String(currentAgentId) || String(data.zoneId || '') !== String(zoneId)) return;
          items.push({ id: docItem.id, ...data });
        });

        fetchedCustomersData = items;
        if (onDataUpdate) onDataUpdate(items);
      }, (err) => {
        console.warn('Firestore customer listener failed:', err);
      });

      const snapshot = await getDocs(customersRef);
      const items = [];
      snapshot.forEach((docItem) => {
        const data = docItem.data() || {};
        if (String(data.agentId) !== String(currentAgentId) || String(data.zoneId || '') !== String(zoneId)) return;
        items.push({ id: docItem.id, ...data });
      });

      fetchedCustomersData = items;
      if (onDataUpdate) onDataUpdate(items);
      return items;
    } catch (err) {
      console.warn('Failed to load customers from Firestore:', err);
      return null;
    }
  }

  async function syncCustomerRecordsToFirestore(customers, zoneId) {
    if (!firestore || !firebaseReady || !currentAgentId || !Array.isArray(customers) || customers.length === 0) return;

    try {
      const { doc, setDoc } = await import('https://www.gstatic.com/firebasejs/11.6.1/firebase-firestore.js');
      const timestamp = new Date().toISOString();
      await Promise.all(customers.map((item) => {
        if (!item?.id) return Promise.resolve();
        const recordRef = doc(firestore, 'customers', String(item.id));
        return setDoc(recordRef, {
          customerId: String(item.id),
          agentId: String(currentAgentId),
          zoneId: zoneId ? String(zoneId) : '',
          name: item.name || '',
          mobile: item.mobile || '',
          username: item.username || '',
          serial: item.serial || '',
          fdt: item.fdt || '',
          fat: item.fat || '',
          expires: item.expires || '',
          remainingDaysText: item.remainingDaysText || '',
          category: item.category || '',
          startedAt: item.startedAt || '',
          status: item.status || '',
          deviceId: item.deviceId || '',
          devicePassword: item.devicePassword || '',
          ontVendor: item.ontVendor || '',
          rxPower: item.rxPower || '',
          rxStatus: item.rxStatus || '',
          note: item.note || '',
          lastSyncedAt: timestamp,
          updatedAt: timestamp,
          source: 'web-app'
        }, { merge: true });
      }));
    } catch (err) {
      console.warn('Customer sync to Firestore failed:', err);
    }
  }

  async function saveNoteToCloud(customerId, noteText) {
    if (!firestore || !firebaseReady || !currentAgentId || !customerId) return;

    userNotes[customerId] = noteText;

    try {
      const { doc, setDoc } = await import('https://www.gstatic.com/firebasejs/11.6.1/firebase-firestore.js');
      const noteItemRef = doc(firestore, 'notes', String(currentAgentId), 'customerNotes', String(customerId));
      const customerDocRef = doc(firestore, 'customers', String(customerId));
      const timestamp = new Date().toISOString();

      await Promise.all([
        setDoc(noteItemRef, {
          note: noteText,
          customerId: String(customerId),
          agentId: String(currentAgentId),
          updatedAt: timestamp,
          source: 'web-app'
        }, { merge: true }),
        setDoc(customerDocRef, {
          note: noteText,
          customerId: String(customerId),
          agentId: String(currentAgentId),
          updatedAt: timestamp,
          source: 'web-app'
        }, { merge: true })
      ]);
    } catch (err) {
      console.warn('Note sync to Firestore failed:', err);
    }
  }

  function setUserNotes(notes) {
    userNotes = { ...userNotes, ...notes };
  }

  function getUserNotes() {
    return userNotes;
  }

  return {
    initFirebase,
    setContext,
    registerDataCallback,
    getState,
    getFirebaseHandles,
    loadCustomersFromFirestore,
    syncCustomerRecordsToFirestore,
    saveNoteToCloud,
    setUserNotes,
    getUserNotes
  };
})();

window.firebaseSync = firebaseSync;
