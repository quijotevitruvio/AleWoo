# AleWoo: Facturación Melos para WooCommerce 🚀🤴

> **Professional WooCommerce & Alegra SAP/ERP Integration for Colombia (DIAN Compliant).**

---

## 🇪🇸 Español

### ¿De qué va esto? (Descripción)

Esta es una solución integral de nivel corporativo para conectar WooCommerce con la **API oficial de Alegra**. Diseñada específicamente para el mercado colombiano, automatiza todo el chicharrón de la facturación electrónica, tesorería e inventario sin depender de herramientas de terceros ni pagos mensuales adicionales. **Este plugin se pega directamente a los servicios de Alegra para garantizar que su contabilidad esté siempre al día.**

### 📦 Cómo descargar el Plugin (ZIP)

Para que lo podás montar en tu WordPress de una, hacé esto:
1. Dale clic al botón verde que dice **"Code"** arriba a la derecha.
2. Elegí la opción **"Download ZIP"**.
3. ¡Listo! Ya tenés el archivo para subirlo a tu sitio.

- **DIAN Compliant**: Validación de NIT, cálculo de Dígito de Verificación (DV) y mapeo geográfico DANE.
- **Automatización de Tesorería**: Generación automática de Recibos de Caja (Payments) vinculados a las facturas al detectar pagos en Woo.
- **Gestión Analítica**: Atribución automática de ventas a Vendedores y segmentación por Centros de Costo.
- **Notas de Crédito Automáticas**: Reembolsos parciales o totales procesados desde WooCommerce y creados como borradores en Alegra para revisión final.
- **Inventario Inteligente**: Sincronización en tiempo real mediante Webhooks securizados por token de 32 caracteres.
- **Sincronización 1 a 1**: Botones para sincronizar el chicharrón manualmente en la lista de productos y dentro de cada pedido.
- **¿Cómo va la vuelta?**: Monitor de salud del sistema y visor de logs en tiempo real para que todo esté ¡Melo!

### 🛠️ Cómo montar el camello (Instalación)

1. Meta la carpetica del plugin en `/wp-content/plugins/`.
2. Prenda esa vuelta (Activa el plugin) desde el panel de WordPress.
3. Váyase para **WooCommerce > AleWoo**.
4. En la pestaña **El Enlace**, ingrese su correo y el Token de API de Alegra.
5. Selecciona el **Banco Global** para que los pagos queden melos.
6. En la pestaña **¿Cómo va la vuelta?**, copia la **URL Segura de Webhook** y pégala en tu panel de Alegra (Configuración > Webhooks).

---

## 🇺🇸 English

### Description

A high-level corporate solution to seamlessly connect WooCommerce with **Alegra**. Specifically engineered for the Colombian market, it automates the entire cycle of electronic invoicing, treasury, and inventory management without relying on third-party tools or external monthly fees.

### ✨ Key Features

- **DIAN Compliant**: NIT validation, Verification Digit (DV) calculation, and DANE geographic mapping.
- **Treasury Automation**: Automatic generation of Cash Receipts (Payments) linked to invoices upon detecting payments in Woo.
- **Analytical Management**: Automatic sale attribution to Sellers and segmentation by Cost Centers.
- **Automatic Credit Notes**: Partial or total refunds processed from WooCommerce and created as drafts in Alegra for final review.
- **Smart Inventory**: Real-time synchronization via Webhooks secured by a 32-character secret token.
- **1-on-1 Sync**: Manual sync buttons available in product lists and individual order pages.
- **Diagnostic Dashboard**: System health monitor and real-time log viewer.

### 🛠️ Installation & Setup

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress 'Plugins' menu.
3. Navigate to **WooCommerce > Alegra Pro**.
4. In the **Connection** tab, enter your Alegra email and API Token.
5. Select the **Global Bank** for payment records.
6. In the **Diagnostic** tab, copy the **Secure Webhook URL** and paste it into your Alegra panel (Settings > Webhooks).

---

## 🔒 Blindaje Total (Seguridad)

Este plugin es una "bestia" de integración que se comunica vía **API REST** con Alegra. Desarrollado con los patrones más tesos de OOP, esta vuelta incluye:

- **Validación de Nonces**: Para que nadie se meta donde no debe en las acciones de AJAX.
- **Token del Webhook**: Una llave secreta solo para que Alegra te actualice el inventario.
- **Control de Acceso**: Solo los duros (`manage_options`) pueden moverle a los ajustes.

## 📄 Los Permisos (Licencia)

Custom Enterprise License.

---
**Developed with ❤️ by [Andrés Valencia Tobón](https://github.com/quijotevitruvio).**
