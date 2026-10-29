# Portal de clientes — Jacqueline Fotografía

Aplicación PHP independiente para que cada cliente acceda a su galería con un código personal. Las fotografías originales se guardan fuera del directorio público y se sirven mediante una ruta PHP que valida sesión, galería y ubicación del archivo.

## Requisitos

- PHP 8.2 o superior con extensiones `pdo_mysql`, `fileinfo` y `gd` (PHP 8.4 en el hosting previsto).
- MySQL 8.0.
- Acceso HTTPS para el entorno publicado.

## Configuración y prueba local

Los ejemplos usan `php` en el `PATH`. En una instalación de XAMPP donde no esté agregado, reemplazalo por `C:\xampp\php\php.exe`.

Habilitá la extensión GD de PHP para generar miniaturas. En XAMPP, activá `extension=gd` en el `php.ini` usado por Apache y reinicialo; para el servidor CLI también verificá la configuración mostrada por `php --ini`.

1. Si todavía no existe `config.local.php`, copiá la plantilla. Si ya tenés una configuración local, conservá sus datos y agregá solamente las opciones que falten:

   ```powershell
   Copy-Item config.local.php.example config.local.php
   ```

   Completá la conexión a MySQL y generá una clave aleatoria para `app_key`:

   ```powershell
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Conservá la misma clave mientras existan códigos activos. El archivo real de configuración está excluido de Git.
2. Si todavía no existen las tablas, creá una base local vacía y ejecutá `database/schema.sql` solo en esa base. No vuelvas a ejecutarlo sobre una base ya configurada. El portal no aplica migraciones automáticamente.
3. Para una prueba desde cero, creá una galería de prueba:

   ```sql
   INSERT INTO galleries (title, event_date)
   VALUES ('Galería de prueba', CURRENT_DATE);
   ```

4. Generá el hash de una contraseña administrativa desde la terminal. PHP pedirá el valor y solo imprimirá el hash:

   ```bash
   php -r '$password = readline("Contraseña administrativa: "); echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;'
   ```

   En `config.local.php`, agregá las credenciales de administración sin guardar la contraseña:

   ```php
   'admin' => [
       'username' => 'tu_usuario_administrativo',
       'password_hash' => 'PEGAR_ACA_EL_HASH_GENERADO',
   ],
   ```

   No uses el texto de ejemplo como credenciales y no copies la contraseña en comandos, archivos públicos ni registros. También podés configurar `ADMIN_USERNAME` y `ADMIN_PASSWORD_HASH` como variables de entorno. No hay un usuario o contraseña predeterminados.

5. Iniciá el servidor de desarrollo desde `clientes-portal`:

   ```powershell
   php -S 127.0.0.1:8000 -t public
   ```

6. Abrí `http://127.0.0.1:8000/admin/login.php` e ingresá con el usuario y la contraseña cuya versión hash configuraste. Desde el panel podés crear una galería, seleccionar una carpeta o varios archivos, cargar fotos y generar el código privado.
7. El código nuevo se muestra una sola vez. Copialo antes de salir de la página. Después podés probar el acceso de la clienta en `http://127.0.0.1:8000/` con ese código.

El comando de terminal para generar códigos de galerías continúa disponible como alternativa:

   ```powershell
   php scripts/create-access-code.php 1
   ```

El selector usa `webkitdirectory` cuando está disponible y conserva la selección múltiple como alternativa. El panel muestra los nombres, cantidad y tamaño total antes de cargar. Cada imagen se envía por separado mediante XHR, de modo que el progreso es real y un fallo no impide continuar con los demás archivos. Los límites son 250 archivos por lote y 25 MB por archivo, reducidos automáticamente si `upload_max_filesize` o `post_max_size` de PHP son menores. Para aceptar imágenes de hasta 25 MB, configurá al menos `upload_max_filesize=25M` y `post_max_size=26M`.

Los duplicados se detectan comparando SHA-256 del contenido exacto con los archivos ya registrados en esa misma galería. Las rutas heredadas se verifican una vez al iniciar cada lote; las nuevas rutas usan el hash como nombre interno y se consultan directamente. Los originales no se recomprimen ni se redimensionan. Esta estrategia usa las tablas actuales y no requiere migración.

También se pueden definir `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_CHARSET`, `APP_KEY`, `PHOTO_STORAGE_PATH`, `ADMIN_USERNAME` y `ADMIN_PASSWORD_HASH` como variables de entorno; tienen prioridad sobre `config.local.php`.

## Seguridad implementada

- Los códigos no se almacenan en texto plano. El generador crea tokens aleatorios de 128 bits y solo guarda su HMAC, único por código.
- Las consultas usan PDO y sentencias preparadas; las consultas nativas/emuladas están desactivadas.
- Las sesiones usan modo estricto, cookies `HttpOnly` y `SameSite=Lax`; en HTTPS las cookies también son `Secure`. El ID de sesión se regenera al entrar y salir.
- Los formularios POST validan un token CSRF. Los intentos fallidos se limitan por dirección de origen con una ventana de 15 minutos y bloqueo después de cinco intentos.
- La galería se vuelve a validar en cada solicitud protegida. La descarga filtra por el ID de galería de la sesión y rechaza rutas fuera del almacenamiento.
- Los originales nunca deben colocarse dentro de `public/`; la descarga solo admite JPEG, PNG y WebP.
- Las miniaturas se generan como derivados privados y también requieren sesión autorizada; la descarga individual entrega el archivo original sin recomprimirlo.
- El panel requiere su propia marca de autenticación en la sesión, independiente del acceso de clientas; la contraseña se valida con `password_verify()` y el identificador de sesión se regenera al entrar.
- Las operaciones administrativas comprueban autenticación y token CSRF. La subida valida MIME y estructura de imagen, usa nombres de contenido SHA-256 y guarda primero un archivo completo privado antes de registrar su ruta.
- Los errores operativos se registran con mensajes genéricos, sin incluir contraseñas, claves ni credenciales.

## Publicación futura en DonWeb

1. Creá la base MySQL y un usuario con permisos limitados a esa base desde el panel de DonWeb. No reutilices credenciales de la web principal.
2. Subí los archivos de la aplicación y configurá el subdominio `clientes.jacquelinefotografia.com` para que su raíz web sea exclusivamente la carpeta `clientes-portal/public`.
3. Ubicá `storage/originals` y el resto de la aplicación fuera de la raíz pública. Si la estructura que ofrece el plan no permite esa separación, consultá a DonWeb antes de publicar; no guardes originales en una carpeta navegable por URL.
4. Configurá las variables de entorno en el panel o creá `config.local.php` en el directorio de la aplicación, fuera de `public/`. Usá secretos aleatorios nuevos y no los subas al repositorio.
5. Habilitá HTTPS y verificá la versión/extensiones de PHP. Configurá la raíz pública del subdominio antes de hacer cualquier prueba.
6. `database/schema.sql` cubre las tablas reutilizadas por el panel; aplicalo únicamente si la base está vacía y todavía no tiene ese esquema. Desde «Eventos creados» se puede eliminar una galería después de confirmar la acción; el panel primero verifica y pone en cuarentena sus originales y miniaturas privadas, revoca sus códigos y elimina los registros asociados. Si detecta archivos faltantes, rutas inseguras o archivos privados sin asociar, cancela la operación y muestra un error.
7. Configurá el usuario y el hash de contraseña administrativa de forma privada antes de habilitar el panel. Generá y compartí los códigos desde el panel autenticado; cada código se muestra una sola vez.

Configurá la raíz pública del subdominio exclusivamente en `clientes-portal/public`, y mantené `config.local.php` y `storage/originals` fuera de ella. El panel y la entrega de imágenes requieren HTTPS en producción.
