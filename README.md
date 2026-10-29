# Farmacia La Bendición — Sistema web (MVP académico)

Implementación de la **Fase 1 y 2 / SRS-FLB-01** del proyecto de Ingeniería de Software I: control de productos, inventario por lotes, compras, ventas, comprobantes básicos, proveedores, clientes, usuarios, alertas y reporte de ventas del día.

**Tecnologías:** PHP 8.1+ con PDO MySQL y Argon2id; MySQL 8 o MariaDB compatible; HTML5, CSS, JavaScript sin dependencias externas; Apache/XAMPP. **Interfaz en español**, pensada para dos usuarios internos, con diseño adaptable a pantallas pequeñas. La moneda C$ es una configuración ilustrativa del prototipo; debe validarse con el negocio antes de la puesta en producción.

## 1. Instalar en Windows con XAMPP

1. Instale XAMPP con PHP 8.1+ y Apache/MariaDB. Verifique que PHP incluya extensiones **pdo_mysql** y soporte **Argon2id** (`php -i`, `password_algos()`). Inicie **Apache** y **MySQL** desde el panel de XAMPP.
2. Descomprima esta carpeta en `C:\xampp\htdocs\farmacia_la_bendicion\`. El archivo principal es `index.php`.
3. Abra `http://localhost/phpmyadmin` y cree una base de datos llamada `farmacia_la_bendicion` con juego de caracteres `utf8mb4`, o ejecute: 
   ```sql
   CREATE DATABASE farmacia_la_bendicion CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. Seleccione la base de datos en phpMyAdmin, abra la pestaña **Importar**, e importe `database/schema.sql`. No es necesario crear tablas manualmente.
5. Duplique `config.example.php`, renómbrelo **`config.php`** y complete nombre de la base de datos, usuario y contraseña de MySQL. Si su XAMPP mantiene MySQL en `3306`, puede conservar ese puerto. En instalaciones de pruebas el usuario puede ser `root`, pero en producción debe ser un usuario específico con permisos limitados.
6. Visite `http://localhost/farmacia_la_bendicion/install.php`. Cree la cuenta de la **propietaria** con una contraseña de 10 caracteres como mínimo. El instalador se bloquea automáticamente cuando existe un usuario.
7. Ingrese en `http://localhost/farmacia_la_bendicion/` con la cuenta creada.

**Importante:** Si el navegador muestra «Configuración pendiente», el archivo `config.php` no existe. Si muestra «No se pudo acceder a la base de datos», compruebe el usuario, el puerto y que se haya importado el SQL. Si aparece un error de controlador, compruebe `pdo_mysql`.

## 2. Recorrido recomendado para empezar

1. **Configuración:** añada nombre, dirección y teléfono aprobados para el comprobante; configure el período de vencimientos cuando la propietaria confirme cuántos días usar.
2. **Productos:** cree medicamentos/productos con código único, precio, condición de prescripción y (si fue acordado) umbral mínimo de existencias. Puede editar precios sin modificar ventas históricas.
3. **Inventario:** para inventario inicial, use **Registrar ajuste justificado**, agregue el lote, la fecha de vencimiento si corresponde y el conteo real. El ajuste requiere motivo y conserva auditoría.
4. **Proveedores y compras:** registre el proveedor y su factura; si la mercadería no llegó, elija *Pendiente*. Cuando llegue, abra la compra y pulse *Confirmar recepción*. Si llegó al momento del registro, seleccione *Recibida*.
5. **Nueva venta:** agregue productos y cantidades. La pantalla calcula importes, total y cambio estimado. El cobro es solo en efectivo. Al confirmar, el servidor vuelve a verificar precios, lotes y cantidades; descuenta automáticamente el inventario y abre el comprobante imprimible.
6. **Alertas y reportes:** consulte stock bajo y vencimientos, cambie fechas del reporte y exporte CSV. Los registros quedan asociados a usuarios.
7. **Usuarios:** cree una cuenta de apoyo ocasional; el permiso de gestión de clientes puede habilitarse expresamente. Por defecto no administra productos, compras, usuarios, reportes ni configuración.

## 3. Requisitos funcionales implementados

| ID | Función | Ruta o comportamiento |
| --- | --- | --- |
| RF-01 | Crear, editar y consultar productos | Productos |
| RF-02 | Distinguir condición de prescripción | Campo y etiqueta en listado/venta |
| RF-03 | Consultar existencias por producto | Productos, Inventario |
| RF-04 | Registrar vencimientos por lote | Compras, ajuste inicial, Inventario |
| RF-05 | Alerta de próximos vencimientos | Alertas, período configurable |
| RF-06 | Confirmar, almacenar y consultar ventas | Nueva venta, Historial |
| RF-07 | Registrar facturas y recibir compras | Compras (pendiente/recibida) |
| RF-08 | Registrar y consultar proveedores | Proveedores |
| RF-09 | Registrar clientes básicos autorizados | Clientes (nombre/teléfono), venta opcional |
| RF-10 | Gestionar usuarios y estados | Usuarios (solo propietaria) |
| RF-12 | Reporte diario con totales y CSV | Reporte diario (solo propietaria) |
| RF-13 | Autenticación y roles | Login y validación de permisos en servidor |
| RF-14 | Subtotales/totales y efectivo | Cálculo en pantalla y de nuevo en servidor |
| RF-15 | Entrada/salida automática de inventario | Transacciones atómicas y movimientos trazables |
| RF-16 | Alerta por existencia ≤ umbral | Umbral individual por producto |
| RF-17 | Consulta de alertas separadas | Alertas: bajo stock, vencido, próximo a vencer |
| RF-18 | Comprobante básico imprimible | Comprobante de cada venta, con advertencia fiscal |

**Numeración:** los identificadores RF-11 y HU-10 no aparecen como funcionalidades vigentes en la matriz del documento entregado. Se conservó la numeración de origen sin inventar requisitos.

## 4. Reglas de negocio y seguridad

- Sin comercio electrónico, pasarelas bancarias, nómina ni contabilidad completa. **No existe flujo de devoluciones**, de acuerdo con las decisiones de alcance más recientes del documento.
- Compra **pendiente**: solo registra la factura; compra **recibida**: suma cada unidad mediante lote/movimiento y conserva la fecha/hora. Cada compra se recibe una sola vez.
- Venta: el sistema ordena la salida por **primera fecha de vencimiento disponible (FEFO)**, sin vender lotes vencidos. Se conserva el historial del lote descontado. Esta política de salida es una decisión protectora para el prototipo y **debe validarse** para producción. Los lotes sin fecha declarada se consideran vendibles provisionalmente; validar exigencias sanitarias por categoría.
- Venta: se consolidan cantidades si se repite un producto en varias líneas; se rechazan cantidades superiores a la existencia. Inserción de venta, detalle, descuento del lote y movimiento se realizan en una **transacción InnoDB**; en caso de error se revierte todo. El formulario incorpora `request_token` único para evitar duplicaciones al reenviar.
- Cálculo: precio vigente al confirmar, sin descuentos o impuestos añadidos. Subtotal = total; precio/total histórico se conserva en la línea. El importe de caja debe ser suficiente. El cambio es calculado.
- Roles: propietaria ve todo; apoyo consulta productos/inventario, registra ventas y consulta únicamente sus propios comprobantes. Gestión de clientes solo con autorización explícita. Los controles se validan en el servidor y no dependen del menú.
- Credenciales: contraseñas con `PASSWORD_ARGON2ID`, `memory_cost=65536`, `time_cost=3`, `threads=1`; verificación con `password_verify` y rehabilitación de hash cuando cambien opciones. **No hay credenciales predeterminadas**. Limitación básica de intentos de login (sesión: 6 intentos/15 min).
- Consultas preparadas PDO, validación de importes/cantidades/fechas, token CSRF, regeneración de identificador de sesión, cookies HttpOnly, encabezados contra incrustación y CSP, registros de auditoría, no eliminación física del historial de ventas.
- Las existencias de lotes vencidos siguen visibles como stock registrado, pero no cuentan como stock vendible. La alerta de umbral se basa en existencias registradas, siguiendo el RF literal; considere una política complementaria para stock realmente vendible al validar el negocio.

## 5. Respaldos y recuperación

El comando `scripts/backup.php` genera un archivo SQL con tablas y registros, incluyendo los hashes de usuarios. Solo puede ejecutarse desde **CLI**, y crea una copia en `storage/backups/` (protegida por `.htaccess` en Apache). Ejecute una vez de prueba:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\farmacia_la_bendicion\scripts\backup.php
```

Para el requisito **RNF-07**, cree una tarea en **Programador de tareas de Windows** que ejecute el comando **cada día**, supervise fallos y copie los respaldos a un destino protegido fuera del directorio público, preferiblemente cifrado. La simple presencia del script **no garantiza** que exista un respaldo de antigüedad ≤24 h: la programación y vigilancia operativa son indispensables.

Para probar **RNF-11**, cree una **base de datos de pruebas nueva**, importe el respaldo desde phpMyAdmin y contraste conteos de `products`, `lots`, `sales`, `sale_lines`, `purchases`, `purchase_lines` y `movements`, además de una venta y una compra concretas. No restaure sobre la base productiva sin una política aprobada.

## 6. Pruebas

Ejecute las pruebas de validación independientes de base de datos:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\farmacia_la_bendicion\tests\unit.php
```

Consulte `tests/PRUEBAS_ACEPTACION.md` para las 24 pruebas funcionales y no funcionales con datos reales, incluidos permisos, movimientos, reenvíos y concurrencia. Las pruebas deben ejecutarse en XAMPP/MariaDB antes de considerar el MVP aceptado. También deben medirse **RNF-01** (al menos 19 de 20 consultas en ≤3 segundos) y **RNF-06** (≥99 % de disponibilidad sobre horario acordado); no pueden certificarse a partir del código fuente.

## 7. Limitaciones y decisiones que requieren validación

**Este proyecto es un prototipo académico funcional, no una certificación de uso clínico/fiscal o de producción.** No se ha validado legislación sanitaria, tributaria o de datos personales aplicable; el documento de referencia remite a dichas comprobaciones. El comprobante **no es factura fiscal oficial**.

Faltan decisiones formales sobre política de dispensación de productos con prescripción (la aplicación solo señala esa condición), moneda/reglas tributarias, cómo tratar lotes sin fecha, umbrales por producto, días de aviso, datos de clientes permitidos, formato del comprobante y numeración, retención legal, navegadores objetivo y disponibilidad. No se deben asumir como definitivas las opciones de implementación seleccionadas para facilitar la demostración.

**Recomendaciones antes de instalar en una farmacia real:** migración y conciliación del inventario físico; HTTPS activado en `config.php`; MySQL con usuario limitado y contraseña; servidor endurecido y respaldo externo; control de acceso a archivos del servidor; pruebas de restauración; revisión de seguridad y legislación aplicable; validación de los 24 casos de aceptación con la propietaria. Evite exponer XAMPP de desarrollo directamente a Internet.

## 8. Estructura del proyecto

```text
farmacia_la_bendicion/
├── index.php                 Pantallas, rutas y acciones HTTP
├── install.php               Instalador de la primera cuenta
├── config.example.php        Plantilla de conexión (copiar a config.php)
├── app/
│   ├── core.php              Conexión, validaciones, sesiones, permisos
│   ├── services.php          Operaciones transaccionales de venta/compra/ajuste
│   ├── views.php             Interfaz de cada sección
│   └── error.php             Errores de autorización y navegación
├── assets/
│   ├── style.css             Diseño adaptable e impresión
│   └── app.js                Interacción, cálculos previos, buscadores
├── database/schema.sql      Modelo físico de datos y claves foráneas
├── scripts/backup.php       Respaldo SQL ejecutado por CLI
├── tests/unit.php           Pruebas de validación sin base de datos
└── tests/PRUEBAS_ACEPTACION.md
```

La estructura es deliberadamente sencilla para facilitar mantenimiento en un entorno académico, sin dependencias Composer ni compilación de frontend.
