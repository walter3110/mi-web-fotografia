<?php
$nombre = $_POST['nombre'];
$email = $_POST['email'];
$tipo = $_POST['tipo_sesion'];
$fecha = $_POST['fecha'];
$mensaje = $_POST['mensaje'];

$destino = "estudio@jacquelinefotografia.com";
$asunto = "Nueva consulta desde la web";

$contenido = "Nombre: $nombre\n";
$contenido .= "Email: $email\n";
$contenido .= "Tipo de sesión: $tipo\n";
$contenido .= "Fecha: $fecha\n\n";
$contenido .= "Mensaje:\n$mensaje";

$headers = "From: estudio@jacquelinefotografia.com";
$headers .= "\r\nReply-To: $email";

mail($destino, $asunto, $contenido, $headers);

header("Location: gracias.html");
?>