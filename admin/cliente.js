import { initializeApp } from "https://www.gstatic.com/firebasejs/10.7.0/firebase-app.js";
import {
  getFirestore,
  doc,
  getDoc,
  updateDoc,
} from "https://www.gstatic.com/firebasejs/10.7.0/firebase-firestore.js";

import {
  getStorage,
  ref,
  uploadBytes,
  getDownloadURL,
} from "https://www.gstatic.com/firebasejs/10.7.0/firebase-storage.js";

const firebaseConfig = {
  apiKey: "TU_API_KEY",
  authDomain: "TU_PROYECTO.firebaseapp.com",
  projectId: "TU_PROYECTO",
  storageBucket: "TU_BUCKET.appspot.com",
};

const app = initializeApp(firebaseConfig);
const db = getFirestore(app);
const storage = getStorage(app);

// 🔑 obtener ID del cliente
const params = new URLSearchParams(window.location.search);
const clienteID = params.get("id");

const estado = document.getElementById("estado");
const galeria = document.getElementById("galeria");

// 🔥 SUBIR FOTOS
window.subirFotos = async () => {
  const files = document.getElementById("inputFotos").files;

  if (files.length === 0) {
    alert("Seleccioná fotos");
    return;
  }

  estado.innerText = "Subiendo fotos...";

  let urls = [];

  for (let file of files) {
    const storageRef = ref(storage, `clientes/${clienteID}/${file.name}`);

    await uploadBytes(storageRef, file);

    const url = await getDownloadURL(storageRef);

    urls.push(url);
  }

  // guardar URLs en firestore
  const clienteRef = doc(db, "clientes", clienteID);
  const snap = await getDoc(clienteRef);

  let fotosActuales = snap.data().fotos || [];

  await updateDoc(clienteRef, {
    fotos: [...fotosActuales, ...urls],
  });

  estado.innerText = "Fotos subidas correctamente";

  mostrarFotos();
};

// 🔥 MOSTRAR FOTOS
async function mostrarFotos() {
  const clienteRef = doc(db, "clientes", clienteID);
  const snap = await getDoc(clienteRef);

  const data = snap.data();

  galeria.innerHTML = "";

  data.fotos.forEach((url) => {
    const img = document.createElement("img");
    img.src = url;
    img.style.width = "150px";
    img.style.margin = "5px";

    galeria.appendChild(img);
  });
}

mostrarFotos();
