import { initializeApp } from "https://www.gstatic.com/firebasejs/10.7.0/firebase-app.js";
import {
  getFirestore,
  collection,
  addDoc,
  getDocs,
} from "https://www.gstatic.com/firebasejs/10.7.0/firebase-firestore.js";

const firebaseConfig = {
  apiKey: "TU_API_KEY",
  authDomain: "TU_PROYECTO.firebaseapp.com",
  projectId: "TU_PROYECTO",
};

const app = initializeApp(firebaseConfig);
const db = getFirestore(app);

const lista = document.getElementById("listaClientes");

// 🔥 CREAR CLIENTE
window.crearCliente = async () => {
  const nombre = document.getElementById("nombreCliente").value;

  if (!nombre) {
    alert("Ingresá un nombre");
    return;
  }

  await addDoc(collection(db, "clientes"), {
    nombre: nombre,
    estado: "pendiente",
    fotos: [],
  });

  location.reload();
};

// 🔥 MOSTRAR CLIENTES
const snapshot = await getDocs(collection(db, "clientes"));

snapshot.forEach((doc) => {
  const data = doc.data();

  const div = document.createElement("div");
  div.className = "card";

  div.innerHTML = `
    <h3>${data.nombre}</h3>
    <p>Estado: ${data.estado}</p>
    <button onclick="abrirCliente('${doc.id}')">Administrar</button>
  `;

  lista.appendChild(div);
});

// 🔥 IR A DETALLE
window.abrirCliente = (id) => {
  window.location.href = `cliente.html?id=${id}`;
};
