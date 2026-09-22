# 👕 MaryClean — Sistema de Gestión de Lavandería
### Documentación Técnica — Backend PHP (CodeIgniter 4) + MySQL

---

## Tabla de Contenidos

1. [Resumen del Proyecto](#1-resumen-del-proyecto)
2. [Stack Tecnológico](#2-stack-tecnológico)
3. [Arquitectura MVC y Base de Datos](#3-arquitectura-mvc-y-base-de-datos)
4. [Módulos Principales](#4-módulos-principales)
5. [Seguridad y JWT (RBAC)](#5-seguridad-y-jwt-rbac)
6. [Rate Limiting (Defensa)](#6-rate-limiting-defensa)
7. [Suite de Pruebas (Testing)](#7-suite-de-pruebas-testing)
8. [Comandos de Referencia Rápida](#8-comandos-de-referencia-rápida)

---

## 1. Resumen del Proyecto

**MaryClean** es una API RESTful diseñada para administrar las operaciones de una cadena de lavanderías. 
Centraliza la gestión de clientes, recepción de prendas (pedidos), seguimiento de estados, cobros y cierres de caja en múltiples sucursales.

---

## 2. Stack Tecnológico

*   **Framework:** CodeIgniter 4 (PHP 8.2+)
*   **Base de Datos:** MySQL (InnoDB) con Constraints, Triggers y Stored Procedures.
*   **Autenticación:** JWT (JSON Web Tokens) mediante `firebase/php-jwt`.
*   **Documentación API:** Swagger UI (OpenAPI 3).

---

## 3. Arquitectura MVC y Base de Datos

El backend adopta el patrón de **Fat Models, Thin Controllers**, y lleva la integridad de los datos un paso más allá inyectando lógica crítica directamente en el motor de base de datos MySQL:

*   **Controladores:** Reciben requests HTTP, validan los inputs, delegan al modelo y responden en JSON estructurado.
*   **Modelos:** Usan el Query Builder de CI4 para lecturas/escrituras.
*   **Base de Datos (Lógica encapsulada):**
    *   **Triggers:** Mantienen actualizados los montos acumulados (`total` del pedido) y evitan inconsistencias como sobrepagos (SQLSTATE 45000).
    *   **Stored Procedures:** Garantizan operaciones atómicas como la creación de pedidos (cabecera y detalles en 1 sola transacción) o el cuadre de caja (uso avanzado de `ROLLUP`).
    *   **Vistas:** Optimizan consultas frecuentes (`v_pedidos_activos`).

---

## 4. Módulos Principales

1.  **Auth:** Inicio de sesión y verificación de tokens.
2.  **Clientes:** Registro y búsqueda rápida.
3.  **Pedidos:** Flujo de estados (Recibido -> En Proceso -> Listo -> Entregado).
4.  **Pagos:** Abonos totales o parciales.
5.  **Reportes:** Cierre de caja, métricas diarias/mensuales y ranking de servicios.
6.  **Empleados & Sucursales:** CRUD administrativo con sistema de *Baja Lógica* (Soft Delete) manual.

---

## 5. Seguridad y JWT (RBAC)

Todo el API (excepto `/auth/login`) requiere un token JWT firmado.
El modelo de acceso basado en roles (RBAC) tiene tres jerarquías:
1.  **Admin:** Acceso ilimitado.
2.  **Cajero:** Ventas, abonos y caja.
3.  **Recepcionista:** Solo registro inicial de pedidos.

El acceso se bloquea dinámicamente tanto a nivel de middleware (Filtros CI4) como a nivel lógico en los controladores.

---

## 6. Rate Limiting (Defensa)

Se ha implementado Throttler nativo de CodeIgniter configurado con caché local (basado en hash MD5 por ruta+IP) para prevenir abusos.
*   60 peticiones/min para listados comunes.
*   10-30 peticiones/min para mutaciones sensibles (crear empleados/sucursales).
*   4 peticiones/min para autenticación (prevención fuerza bruta).

---

## 7. Suite de Pruebas (Testing)

El entorno incluye pruebas unitarias completas sobre los modelos y la lógica de base de datos.
Base de datos aislada: `lavanderia_test`.

```bash
# Ejecutar todas las pruebas
vendor\bin\phpunit

# Generar reporte de cobertura (HTML)
vendor\bin\phpunit --coverage-html build/logs/html
```

---

## 8. Comandos de Referencia Rápida

```bash
# Iniciar servidor local
php spark serve

# Migraciones y Seeders (Poblar BD)
php spark migrate
php spark db:seed LavanderiaSeeder
```
