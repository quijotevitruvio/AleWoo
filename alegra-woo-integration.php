<?php
/**
 * Plugin Name: AleWoo: Facturación Melos para WooCommerce 🚀🤴
 * Description: Integración avanzada para Facturación Electrónica DIAN, Inventario Multibodega y Notas de Crédito Automáticas.
 * Version: 6.0.4
 * Author: Andrés Valencia Tobón
 * Author URI: https://github.com/quijotevitruvio
 * Text Domain: alegra-woo-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase principal del Plugin
 */
class Alegra_Woo_Pro {

    private static $instance = null;
    private $option_name = 'alegra_pro_settings';

    public static function get_instance() {
        if ( null == self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Generar Webhook Secret si no existe (v6.0.0)
        $options = get_option( $this->option_name );
        if ( empty( $options['webhook_secret'] ) ) {
            $options['webhook_secret'] = wp_generate_password( 32, false );
            update_option( $this->option_name, array_merge( (array)$options, array( 'webhook_secret' => $options['webhook_secret'] ) ) );
        }

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        
        // Hooks de Facturación y Reembolsos
        add_action( 'woocommerce_order_status_completed', array( $this, 'process_alegra_invoice' ), 10, 1 );
        add_action( 'woocommerce_order_refunded', array( $this, 'process_alegra_partial_refund' ), 10, 2 );

        // Metabox y Acciones Manuales
        add_action( 'add_meta_boxes', array( $this, 'add_alegra_metabox' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_alegra_product_metabox' ) );
        add_action( 'wp_ajax_alegra_manual_sync', array( $this, 'handle_manual_sync' ) );
        add_action( 'wp_ajax_alegra_sync_single_product', array( $this, 'ajax_sync_single_product' ) );
        add_action( 'wp_ajax_alegra_test_connection', array( $this, 'ajax_test_connection' ) );
        
        // Acciones en la lista de productos
        add_filter( 'post_row_actions', array( $this, 'add_product_list_sync_button' ), 10, 2 );
        add_action( 'admin_footer', array( $this, 'add_product_list_js' ) );

        // Sincronización de Stock (Webhook)
        add_action( 'rest_api_init', array( $this, 'register_stock_webhook' ) );
    }

    /**
     * Estilos Premium para el Dashboard
     */
    public function enqueue_styles( $hook ) {
        if ( 'woocommerce_page_alegra-pro-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'google-fonts-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Alegra Integration',
            'Alegra Pro',
            'manage_options',
            'alegra-pro-settings',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting( $this->option_name, $this->option_name );
    }

    /**
     * Escanea la base de datos en busca de llaves meta que empiecen por billing_ o _billing_
     */
    private function get_available_billing_keys() {
        global $wpdb;
        $keys = $wpdb->get_col( "
            (SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '%billing%')
            UNION
            (SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%billing%')
            ORDER BY meta_key ASC
        " );
        
        $cleaned_keys = array();
        foreach ( $keys as $k ) {
            $cleaned_keys[] = ltrim( $k, '_' ); // Mostrar versión sin guión bajo para legibilidad
        }
        return array_unique( $cleaned_keys );
    }

    public function settings_page() {
        $meta_keys = $this->get_available_billing_keys();
        ?>
        <style>
            #alegra-pro-wrap { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; margin: 20px auto; max-width: 900px; padding: 30px; background: #fff; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #edf2f7; }
            .alegra-badge { display: inline-block; padding: 4px 12px; border-radius: 50px; background: #e6fffa; color: #2c7a7b; font-weight: 700; font-size: 11px; margin-bottom: 10px; }
            .alegra-tabs { display: flex; gap: 10px; border-bottom: 2px solid #edf2f7; margin-bottom: 25px; }
            .alegra-tab { padding: 12px 20px; cursor: pointer; font-weight: 600; color: #a0aec0; border-bottom: 2px solid transparent; transition: 0.3s; }
            .alegra-tab:hover { color: #3182ce; }
            .alegra-tab.active { color: #3182ce; border-bottom-color: #3182ce; }
            .alegra-tab-content { display: none; animation: fadeIn 0.4s; }
            .alegra-tab-content.active { display: block; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
            .alegra-section { background: #f7fafc; padding: 25px; border-radius: 10px; margin-bottom: 25px; }
            .form-table th { width: 220px; color: #4a5568; }
            .form-table td input[type="text"], .form-table td input[type="password"], .form-table td select { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
        </style>

        <div id="alegra-pro-wrap">
            <span class="alegra-badge">Enterprise v3.5</span>
            <h1>Configuración Alegra Pro</h1>
            <p class="description">Gestión avanzada de facturación e inventario DIAN.</p>
            
            <div class="alegra-tabs">
                <a href="#tab-conexion" class="alegra-nav-item active" data-tab="tab-conexion">🔗 El Enlace</a>
                <a href="#tab-configuracion" class="alegra-nav-item" data-tab="tab-configuracion">⚙️ Ajustes</a>
                <a href="#tab-mapeo" class="alegra-nav-item" data-tab="tab-mapeo">🔄 Cruces de Data</a>
                <a href="#tab-diagnostico" class="alegra-nav-item" data-tab="tab-diagnostico">📡 ¿Cómo va la vuelta?</a>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_name );
                $options = get_option( $this->option_name );
                ?>

                <!-- TAB CONEXION (EL ENLACE) -->
                <div id="tab-conexion" class="alegra-tab-content active">
                    <div class="alegra-section">
                        <h2>Conecte esa vuelta (API)</h2>
                        <p class="description">Aquí es donde enganchamos WooCommerce con Alegra para que todo fluya.</p>
                        <table class="form-table">
                            <tr>
                                <th>Email de Alegra</th>
                                <td><input type="text" name="<?php echo $this->option_name; ?>[email]" value="<?php echo esc_attr( isset($options['email']) ? $options['email'] : '' ); ?>" placeholder="ejem@tuempresa.com" /></td>
                            </tr>
                            <tr>
                                <th>Token de API</th>
                                <td>
                                    <input type="password" id="alegra_token" name="<?php echo $this->option_name; ?>[token]" value="<?php echo esc_attr( isset($options['token']) ? $options['token'] : '' ); ?>" />
                                    <button type="button" id="btn-test-connection" class="button button-secondary">Verificar Conexión</button>
                                    <span id="connection-status" style="margin-left: 10px; font-weight: 600;"></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Banco para Pagos</th>
                                <td>
                                    <?php $banks = $this->get_alegra_banks(); ?>
                                    <select name="<?php echo $this->option_name; ?>[bank_account_id]">
                                        <option value="">-- Seleccionar Banco Global --</option>
                                        <?php foreach ( $banks as $b ) : ?>
                                            <option value="<?php echo esc_attr($b['id']); ?>" <?php selected( isset($options['bank_account_id']) ? $options['bank_account_id'] : '', $b['id'] ); ?>><?php echo esc_html($b['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br><small>Los pagos recibidos en WooCommerce se registrarán en esta cuenta de Alegra.</small>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- TAB CONFIGURACION -->
                <div id="tab-config" class="alegra-tab-content">
                    <div class="alegra-section">
                        <h2>Configuración Fiscal (DIAN)</h2>
                        <p class="description">Ajustes avanzados para la emisión de facturas.</p>
                        <table class="form-table">
                            <tr>
                                <th>Facturar como Crédito si</th>
                                <td>
                                    <select name="<?php echo $this->option_name; ?>[payment_form]">
                                        <option value="CASH" <?php selected( isset($options['payment_form']) ? $options['payment_form'] : '', 'CASH' ); ?>>Siempre Contado</option>
                                        <option value="CREDIT" <?php selected( isset($options['payment_form']) ? $options['payment_form'] : '', 'CREDIT' ); ?>>Siempre Crédito</option>
                                    </select>
                                    <br><small>Determina si la factura nace pagada o como cuenta por cobrar.</small>
                                </td>
                            </tr>
                                    <br><small>Las ventas de WooCommerce se atribuirán a este vendedor en Alegra.</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Centro de Costo</th>
                                <td>
                                    <?php $cost_centers = $this->get_alegra_cost_centers(); ?>
                                    <select name="<?php echo $this->option_name; ?>[cost_center_id]">
                                        <option value="">-- Sin Centro de Costo --</option>
                                        <?php foreach ( $cost_centers as $cc ) : ?>
                                            <option value="<?php echo esc_attr($cc['id']); ?>" <?php selected( isset($options['cost_center_id']) ? $options['cost_center_id'] : '', $cc['id'] ); ?>><?php echo esc_html($cc['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br><small>Clasifica contablemente tus ventas web en Alegra.</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Meta-key Número Identificación</th>
                                <td>
                                    <select name="<?php echo $this->option_name; ?>[identification_meta_key]">
                                        <option value="">-- Seleccionar Campo Identificación --</option>
                                        <?php foreach ( $meta_keys as $key ) : ?>
                                            <option value="<?php echo esc_attr($key); ?>" <?php selected( isset($options['identification_meta_key']) ? $options['identification_meta_key'] : 'billing_nit', $key ); ?>><?php echo esc_html($key); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br><small>Campo que contiene el número (NIT/Cédula/etc.)</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Meta-key Tipo Documento</th>
                                <td>
                                    <select name="<?php echo $this->option_name; ?>[tipo_documento_meta_key]">
                                        <option value="">-- Seleccionar Campo Tipo --</option>
                                        <?php foreach ( $meta_keys as $key ) : ?>
                                            <option value="<?php echo esc_attr($key); ?>" <?php selected( isset($options['tipo_documento_meta_key']) ? $options['tipo_documento_meta_key'] : 'billing_tipo_documento', $key ); ?>><?php echo esc_html($key); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br><small>Campo que contiene el tipo (CC, NIT, PP, etc.)</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Responsabilidad Fiscal</th>
                                <td>
                                    <input type="text" name="<?php echo $this->option_name; ?>[default_fiscal_responsibility]" value="<?php echo esc_attr( isset($options['default_fiscal_responsibility']) ? $options['default_fiscal_responsibility'] : 'R-99-PN' ); ?>" />
                                    <small>PN: R-99-PN, PJ Responsable IVA: O-13</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Bodega de Despacho</th>
                                <td>
                                    <?php $warehouses = $this->get_alegra_warehouses(); ?>
                                    <select name="<?php echo $this->option_name; ?>[warehouse_id]">
                                        <option value="">Seleccionar Bodega (Default: Principal)</option>
                                        <?php foreach ( $warehouses as $w ) : ?>
                                            <option value="<?php echo esc_attr($w['id']); ?>" <?php selected( isset($options['warehouse_id']) ? $options['warehouse_id'] : '', $w['id'] ); ?>><?php echo esc_html($w['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>ID Impuesto por Defecto</th>
                                <td><input type="text" name="<?php echo $this->option_name; ?>[default_tax_id]" value="<?php echo esc_attr( isset($options['default_tax_id']) ? $options['default_tax_id'] : '1' ); ?>" /></td>
                            </tr>
                        </table>
                    </div>

                    <div class="alegra-section" style="background: #fffaf0; border: 1px solid #feebc8;">
                        <h2 style="color: #9c4221;">📡 Configuración de Webhook (Alegra → Woo)</h2>
                        <p class="description">Copia esta URL <b>SEGURA</b> y pégala en <b>Configuración > Webhooks</b> de tu panel de Alegra:</p>
                        <?php 
                        $webhook_url = get_rest_url( null, 'alegrawoo/v1/stock-sync' );
                        $webhook_url = add_query_arg( 'token', $options['webhook_secret'], $webhook_url );
                        ?>
                        <code style="display: block; padding: 15px; background: #fff; border-radius: 6px; border: 1px solid #fbd38d; color: #744210; font-size: 13px; word-break: break-all;">
                            <?php echo esc_url( $webhook_url ); ?>
                        </code>
                        <p><small>⚠️ <b>Atención:</b> Sin el parámetro <code>token</code>, Alegra no podrá actualizar el inventario.</small></p>
                        <p style="font-size: 11px; color: #9c4221; margin-top: 10px;">⚠️ Eventos recomendados: <b>"Item creado"</b> e <b>"Item actualizado"</b>.</p>
                    </div>
                </div>

                <!-- TAB MAPEO -->
                <div id="tab-mapeo" class="alegra-tab-content">
                    <div class="alegra-section">
                        <h2>Mapeo de Métodos de Pago (DIAN + Tesorería)</h2>
                        <p class="description">Configura cómo se reportan los pagos a la DIAN y cómo se registran en tu contabilidad.</p>
                        <table class="form-table">
                            <thead>
                                <tr>
                                    <th style="padding: 10px; text-align: left;">Pasarela Woo</th>
                                    <th style="padding: 10px; text-align: left;">Código DIAN</th>
                                    <th style="padding: 10px; text-align: left;">Método Alegra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $gateways = WC()->payment_gateways->get_available_payment_gateways();
                                $payment_map = isset($options['payment_map']) ? $options['payment_map'] : array();
                                $payment_method_map = isset($options['payment_method_map']) ? $options['payment_method_map'] : array();
                                
                                $alegra_methods = array(
                                    'cash'           => 'Efectivo',
                                    'transfer'       => 'Transferencia',
                                    'deposit'        => 'Depósito',
                                    'check'          => 'Cheque',
                                    'credit-card'    => 'Tarjeta de Crédito',
                                    'debit-card'     => 'Tarjeta de Débito',
                                    'other'          => 'Otro'
                                );

                                foreach ( $gateways as $id => $gateway ) :
                                    $current_dian = isset($payment_map[$id]) ? $payment_map[$id] : '47';
                                    $current_alegra = isset($payment_method_map[$id]) ? $payment_method_map[$id] : 'transfer';
                                ?>
                                <tr>
                                    <td><b><?php echo esc_html($gateway->get_title()); ?></b></td>
                                    <td>
                                        <input type="text" name="<?php echo $this->option_name; ?>[payment_map][<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr($current_dian); ?>" style="width: 60px;" />
                                    </td>
                                    <td>
                                        <select name="<?php echo $this->option_name; ?>[payment_method_map][<?php echo esc_attr($id); ?>]">
                                            <?php foreach ( $alegra_methods as $val => $label ) : ?>
                                                <option value="<?php echo $val; ?>" <?php selected($current_alegra, $val); ?>><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB DIAGNOSTICO (¿CÓMO VA LA VUELTA?) -->
                <div id="tab-diagnostico" class="alegra-tab-content">
                    <div class="alegra-section">
                        <h2>¿Cómo va la vuelta? (Salud del Sistema)</h2>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                            <div style="background: #f0fff4; padding: 15px; border-radius: 8px; border: 1px solid #c6f6d5; text-align: center;">
                                <div style="font-size: 24px;">📡</div>
                                <div style="font-weight: bold; color: #22543d;">API Status</div>
                                <div style="font-size: 11px; color: #276749;">¡Melo! (Conectado)</div>
                            </div>
                            <div style="background: <?php echo !empty($options['bank_account_id']) ? '#f0fff4' : '#fff5f5'; ?>; padding: 15px; border-radius: 8px; border: 1px solid <?php echo !empty($options['bank_account_id']) ? '#c6f6d5' : '#feb2b2'; ?>; text-align: center;">
                                <div style="font-size: 24px;">🏦</div>
                                <div style="font-weight: bold; color: <?php echo !empty($options['bank_account_id']) ? '#22543d' : '#822727'; ?>;">Bancos</div>
                                <div style="font-size: 11px; color: <?php echo !empty($options['bank_account_id']) ? '#276749' : '#9b2c2c'; ?>;"><?php echo !empty($options['bank_account_id']) ? '¡Pa\' esa!' : 'Falta configurar'; ?></div>
                            </div>
                            <div style="background: #ebf8ff; padding: 15px; border-radius: 8px; border: 1px solid #bee3f8; text-align: center;">
                                <div style="font-size: 24px;">🛠️</div>
                                <div style="font-weight: bold; color: #2a4365;">Versión</div>
                                <div style="font-size: 11px; color: #2c5282;">v6.0.0 Ent.</div>
                            </div>
                        </div>
                        
                        <h2>Logs de Sincronización Recientes</h2>
                        <p class="description">Historial de las últimas comunicaciones con la API de Alegra.</p>
                        <div id="alegra-logs-container" style="max-height: 400px; overflow-y: auto; background: #1a202c; color: #a0aec0; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 11px;">
                            <?php
                            $logs = get_option( 'alegra_sync_logs', array() );
                            if ( empty($logs) ) {
                                echo "No hay logs registrados aún.";
                            } else {
                                foreach ( array_reverse($logs) as $log ) {
                                    echo "<div>[".esc_html($log['time'])."] ".esc_html($log['msg'])."</div><hr style='border-color: #2d3748;'>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <?php submit_button('Guardar Configuración Especializada'); ?>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($){
                // Lógica de Pestañas Mejorada
                $('.alegra-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).attr('data-target');
                    
                    $('.alegra-tab').removeClass('active');
                    $(this).addClass('active');

                    $('.alegra-tab-content').hide().removeClass('active');
                    $('#' + target).fadeIn().addClass('active');
                });

                // Test de Conexión
                $('#btn-test-connection').on('click', function(e) {
                    e.preventDefault();
                    var email = $('input[name="alegra_pro_settings[email]"]').val();
                    var token = $('#alegra_token').val();
                    var $status = $('#connection-status');

                    $status.html(' ⏳ Verificando...').css('color', '#a0aec0');

                    $.post(ajaxurl, {
                        action: 'alegra_test_connection',
                        email: email,
                        token: token,
                        _ajax_nonce: '<?php echo wp_create_nonce("alegra_test_conn"); ?>'
                    }, function(response) {
                        if (response.success) {
                            $status.html(' ✅ Conectado: ' + response.data.name).css('color', '#38a169');
                        } else {
                            $status.html(' ❌ Error: ' + response.data).css('color', '#e53e3e');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Lógica de Facturación Principal
     */
    public function process_alegra_invoice( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $options = get_option( $this->option_name );
        if ( empty($options['email']) || empty($options['token']) ) {
            $order->add_order_note( 'Alegra Error: Credenciales no configuradas.' );
            return;
        }

        $auth = base64_encode( $options['email'] . ':' . $options['token'] );
        $default_tax_id = isset($options['default_tax_id']) ? $options['default_tax_id'] : 1;

        // 1. Obtener o Crear Cliente
        $client_id = $this->get_or_create_client( $order, $auth );
        if ( ! $client_id ) {
            $order->add_order_note( 'Alegra Error: No se pudo gestionar el cliente.' );
            return;
        }

        // 2. Construir Ítems
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku = $product->get_sku();
            
            if ( ! empty( $sku ) ) {
                // Asegurar que el producto existe en Alegra
                $this->get_or_create_item( $product, $auth, $default_tax_id );

                $items[] = array(
                    'reference' => $sku,
                    'price'     => $item->get_subtotal() / $item->get_quantity(),
                    'quantity'  => $item->get_quantity(),
                    'taxes'     => array(
                        array( 'id' => $this->get_matching_tax_id( $item, $auth, $default_tax_id ) )
                    )
                );
            }
        }

        // 3. Envío
        if ( $order->get_shipping_total() > 0 ) {
            $shipping_sku = isset($options['sku_shipping']) ? $options['sku_shipping'] : 'SKU-ENVIO';
            $items[] = array(
                'reference' => $shipping_sku,
                'price'     => $order->get_shipping_total(),
                'quantity'  => 1,
                'taxes'     => array(
                    array( 'id' => $default_tax_id )
                )
            );
        }

        // 4. Determinar Pago, Bodega y Forma de Pago
        $payment_method_id = $order->get_payment_method();
        $dian_code = isset($options['payment_map'][$payment_method_id]) ? $options['payment_map'][$payment_method_id] : '47';
        $warehouse_id = isset($options['warehouse_id']) ? $options['warehouse_id'] : '';
        $payment_form = isset($options['payment_form']) ? $options['payment_form'] : 'CASH';
        $seller_id = isset($options['seller_id']) ? $options['seller_id'] : '';
        $cost_center_id = isset($options['cost_center_id']) ? $options['cost_center_id'] : '';

        // 5. Enviar Factura
        $payload = array(
            'date'    => date('Y-m-d'),
            'dueDate' => date('Y-m-d'),
            'client'  => array( 'id' => $client_id ),
            'items'   => $items,
            'paymentForm'   => $payment_form,
            'paymentMethod' => $dian_code,
            'stamp'   => array( 'generateStamp' => true )
        );

        if ( ! empty( $seller_id ) ) {
            $payload['seller'] = array( 'id' => $seller_id );
        }

        if ( ! empty( $cost_center_id ) ) {
            $payload['costCenter'] = array( 'id' => $cost_center_id );
        }

        if ( $payment_form === 'CREDIT' ) {
            $payload['dueDate'] = date( 'Y-m-d', strtotime('+30 days') ); // Default 30 días si es crédito
        }

        if ( ! empty( $warehouse_id ) ) {
            $payload['warehouse'] = array( 'id' => $warehouse_id );
        }

        $this->add_sync_log( "Enviando factura Pedido #{$order_id} a Alegra. Forma: {$payment_form}, Método: {$dian_code}" );

        $response = wp_remote_post( 'https://api.alegra.com/api/v1/invoices', array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ),
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 45
        ));

        if ( is_wp_error( $response ) ) {
            $err_msg = $response->get_error_message();
            $order->add_order_note( 'Alegra API Error: ' . $err_msg );
            $this->add_sync_log( "❌ Error API Pedido #{$order_id}: {$err_msg}" );
        } else {
            $http_code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            
            if ( $http_code == 200 || $http_code == 201 ) {
                $order->update_meta_data( '_alegra_invoice_id', $body['id'] );
                $order->save();
                $order->add_order_note( '✅ Factura Alegra emitida exitosamente (ID: ' . $body['id'] . '). Pago: ' . $dian_code );
                $this->add_sync_log( "✅ Éxito Pedido #{$order_id}. Factura ID: {$body['id']}" );

                // REGISTRO AUTOMÁTICO DE PAGO (TESORERÍA)
                if ( $order->is_paid() ) {
                    $this->add_sync_log( "💰 Pedido #$order_id pagado. Registrando Recibo de Caja..." );
                    $payment_id = $this->record_alegra_payment( $body['id'], $order, $auth, $client_id );
                    if ( $payment_id ) {
                        $order->update_meta_data( '_alegra_payment_id', $payment_id );
                        $order->save();
                    }
                }
            } else {
                $msg = isset($body['message']) ? $body['message'] : 'Error desconocido';
                $order->add_order_note( '❌ Error Alegra (' . $http_code . '): ' . $msg );
                $this->add_sync_log( "❌ Error Alegra Pedido #{$order_id} ({$http_code}): " . wp_json_encode($body) );
                
                // Log técnico
                if ( class_exists('WC_Logger') ) {
                    $logger = wc_get_logger();
                    $logger->error( "Error Alegra Order #{$order_id}: " . wp_json_encode($body), array( 'source' => 'alegra-pro' ) );
                }
            }
        }
    }

    /**
     * Buscar o Crear Producto en Alegra
     */
    private function get_or_create_item( $product, $auth, $default_tax_id ) {
        $sku = $product->get_sku();
        if ( empty( $sku ) ) return false;

        // 1. Buscar por SKU (reference)
        $search_url = 'https://api.alegra.com/api/v1/items?query=' . urlencode($sku);
        $search = wp_remote_get( $search_url, array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( ! is_wp_error( $search ) && wp_remote_retrieve_response_code( $search ) == 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $search ), true );
            if ( is_array($data) ) {
                foreach ( $data as $alegra_item ) {
                    if ( isset($alegra_item['reference']) && $alegra_item['reference'] === $sku ) {
                        return $alegra_item['id'];
                    }
                }
            }
        }

        // 2. Crear si no existe
        $payload = array(
            'name'      => $product->get_name(),
            'reference' => $sku,
            'price'     => array(
                array( 'price' => $product->get_price() )
            ),
            'inventory' => array(
                'unit' => 'unit'
            ),
            'tax' => array(
                array( 'id' => $default_tax_id )
            )
        );

        $create = wp_remote_post( 'https://api.alegra.com/api/v1/items', array(
            'headers'   => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ),
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 30
        ));

        if ( ! is_wp_error( $create ) ) {
            $http_code = wp_remote_retrieve_response_code( $create );
            if ( $http_code == 200 || $http_code == 201 ) {
                $data = json_decode( wp_remote_retrieve_body( $create ), true );
                return $data['id'];
            }
        }

        return false;
    }

    /**
     * Obtener bodegas de Alegra
     */
    private function get_alegra_banks() {
        $options = get_option( $this->option_name );
        if ( empty($options['email']) || empty($options['token']) ) return array();

        $auth = base64_encode( $options['email'] . ':' . $options['token'] );
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/bank-accounts', array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) return array();
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private function get_alegra_cost_centers() {
        $options = get_option( $this->option_name );
        if ( empty($options['email']) || empty($options['token']) ) return array();

        $auth = base64_encode( $options['email'] . ':' . $options['token'] );
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/cost-centers', array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) return array();
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private function get_alegra_sellers() {
        $options = get_option( $this->option_name );
        if ( empty($options['email']) || empty($options['token']) ) return array();

        $auth = base64_encode( $options['email'] . ':' . $options['token'] );
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/sellers', array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) return array();
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private function get_alegra_warehouses() {
        $options = get_option( $this->option_name );
        if ( empty($options['email']) || empty($options['token']) ) return array();

        $auth = base64_encode( $options['email'] . ':' . $options['token'] );
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/warehouses', array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) == 200 ) {
            return json_decode( wp_remote_retrieve_body( $response ), true );
        }
        return array();
    }

    /**
     * Calcula el Dígito de Verificación (DV) para un NIT colombiano.
     */
    private function calculate_nit_dv( $nit ) {
        $nit = preg_replace( '/\D/', '', $nit );
        if ( empty($nit) ) return '';
        
        $multipliers = array( 3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71 );
        $sum = 0;
        $len = strlen( $nit );
        
        for ( $i = 0; $i < $len; $i++ ) {
            $sum += (int) $nit[$len - 1 - $i] * $multipliers[$i];
        }
        
        $remainder = $sum % 11;
        if ( $remainder > 1 ) {
            return (string) ( 11 - $remainder );
        }
        
        return (string) $remainder;
    }

    /**
     * Añade un registro al historial de logs (Visor de Diagnóstico).
     */
    private function add_sync_log( $msg ) {
        $logs = get_option( 'alegra_sync_logs', array() );
        $logs[] = array(
            'time' => date('Y-m-d H:i:s'),
            'msg'  => $msg
        );
        
        // Mantener solo los últimos 20
        if ( count($logs) > 20 ) {
            array_shift($logs);
        }
        
        update_option( 'alegra_sync_logs', $logs );
    }

    /**
     * Normalización de Ubicación Geográfica para Colombia
     */
    private function normalize_location( $state_code, $city_name ) {
        $map = array(
            'AMA' => 'Amazonas', 'ANT' => 'Antioquia', 'ARA' => 'Arauca', 'ATL' => 'Atlántico',
            'DC'  => 'Bogotá', 'BOL' => 'Bolívar', 'BOY' => 'Boyacá', 'CAL' => 'Caldas',
            'CAQ' => 'Caquetá', 'CAS' => 'Casanare', 'CAU' => 'Cauca', 'CES' => 'Cesar',
            'CHO' => 'Chocó', 'COR' => 'Córdoba', 'CUN' => 'Cundinamarca', 'GUA' => 'Guainía',
            'GUV' => 'Guaviare', 'HUI' => 'Huila', 'LAG' => 'La Guajira', 'MAG' => 'Magdalena',
            'MET' => 'Meta', 'NAR' => 'Nariño', 'NSA' => 'Norte de Santander', 'PUT' => 'Putumayo',
            'QUI' => 'Quindío', 'RIS' => 'Risaralda', 'SAP' => 'San Andrés y Providencia',
            'SAN' => 'Santander', 'SUC' => 'Sucre', 'TOL' => 'Tolima', 'VAC' => 'Valle del Cauca',
            'VAU' => 'Vaupés', 'VID' => 'Vichada'
        );

        $department = isset( $map[$state_code] ) ? $map[$state_code] : $state_code;

        // Limpieza de Ciudad
        $city = trim( $city_name );
        $city = preg_replace( '/\s+D\.?C\.?$/i', '', $city ); // Quitar D.C.
        $city = preg_replace( '/^municipio de /i', '', $city ); // Quitar "Municipio de"
        $city = str_replace( array('á','é','í','ó','ú','ñ'), array('a','e','i','o','u','n'), strtolower($city) );
        $city = ucwords( $city );

        // Casos especiales comunes
        if ( strtolower($city) === 'bogota' ) $city = 'Bogotá';

        return array(
            'department' => $department,
            'city'       => $city
        );
    }

    /**
     * Agrega un metabox de Alegra Pro en el panel lateral de pedidos.
     */
    public function add_alegra_metabox() {
        add_meta_box( 'alegra_sync_box', 'Alegra Pro: Acciones', array( $this, 'render_alegra_metabox' ), 'shop_order', 'side', 'high' );
    }

    public function render_alegra_metabox( $post ) {
        $order = wc_get_order( $post->ID );
        $invoice_id = $order->get_meta( '_alegra_invoice_id', true );
        ?>
        <div style="padding: 10px 0;">
            <p><strong>Estado:</strong> <?php echo $invoice_id ? '<span style="color: green;">✅ Sincronizado</span> (ID: '.$invoice_id.')' : '<span style="color: orange;">⏳ Pendiente</span>'; ?></p>
            <button type="button" id="btn-alegra-sync" class="button button-primary" data-order="<?php echo $post->ID; ?>" <?php echo $invoice_id ? 'disabled' : ''; ?>>
                <?php echo $invoice_id ? 'Factura Emitida' : 'Sincronizar con Alegra'; ?>
            </button>
            <div id="alegra-msg" style="margin-top: 10px;"></div>
        </div>
        <script>
            jQuery(document).ready(function($){
                $('#btn-alegra-sync').click(function(){
                    var btn = $(this);
                    btn.prop('disabled', true).text('Procesando...');
                    $.post(ajaxurl, {
                        action: 'alegra_manual_sync',
                        order_id: btn.data('order'),
                        nonce: '<?php echo wp_create_nonce("alegra_sync"); ?>'
                    }, function(res){
                        $('#alegra-msg').html(res.data.message);
                        if(res.success) {
                            btn.text('¡Éxito!').fadeOut();
                        } else {
                            btn.prop('disabled', false).text('Re-intentar');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function handle_manual_sync() {
        check_ajax_referer( 'alegra_sync', 'nonce' );
        $order_id = intval( $_POST['order_id'] );
        $this->process_alegra_invoice( $order_id );
        wp_send_json_success( array( 'message' => '<p style="color: green;">Sincronización finalizada. Revisa las notas del pedido.</p>' ) );
    }

    /**
     * Procesar Reembolso (Nota de Crédito)
     */
    public function process_alegra_refund( $order_id ) {
        $order = wc_get_order( $order_id );
        $invoice_id = $order->get_meta( '_alegra_invoice_id', true );
        if ( ! $invoice_id ) return;

        $options = get_option( $this->option_name );
        $auth = base64_encode( $options['email'] . ':' . $options['token'] );

        $payload = array(
            'date'              => date('Y-m-d'),
            'invoiceId'         => $invoice_id,
            'reason'            => 'Anulación por pedido reembolsado en WooCommerce',
            'items'             => array(), // Alegra gestiona la devolución total si se envía vacío o bajo ciertos esquemas
            'stamp'             => array( 'generateStamp' => true )
        );

        // Intentar crear Nota de Crédito
        wp_remote_post( 'https://api.alegra.com/api/v1/credit-notes', array(
            'headers'   => array( 'Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/json' ),
            'body'      => wp_json_encode( $payload )
        ));

        $order->add_order_note( 'ℹ️ Se ha solicitado la creación de una Nota de Crédito en Alegra por reembolso.' );
    }

    /**
     * REST API para Sincronización de Stock
     */
    public function register_stock_webhook() {
        register_rest_route( 'alegrawoo/v1', '/stock-sync', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'update_stock_from_alegra' ),
            'permission_callback' => array( $this, 'validate_webhook_token' )
        ));
    }

    public function validate_webhook_token( $request ) {
        $options = get_option( $this->option_name );
        $token   = $request->get_param('token');
        return ( ! empty( $options['webhook_secret'] ) && $token === $options['webhook_secret'] );
    }

    public function update_stock_from_alegra( $request ) {
        $data = $request->get_json_params();
        if ( ! isset($data['item']['reference']) || ! isset($data['item']['inventory']['stock']) ) {
            return new WP_Error( 'invalid_data', 'Datos incompletos', array( 'status' => 400 ) );
        }

        $sku = $data['item']['reference'];
        $new_stock = $data['item']['inventory']['stock'];

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            $product->set_stock_quantity( $new_stock );
            $product->save();
            return new WP_REST_Response( array( 'success' => true, 'id' => $product_id ), 200 );
        }

        return new WP_REST_Response( array( 'success' => false, 'msg' => 'SKU no encontrado' ), 404 );
    }

    /**
     * Registrar Pago (Recibo de Caja) en Alegra
     */
    private function record_alegra_payment( $invoice_id, $order, $auth, $client_id ) {
        $options = get_option( $this->option_name );
        $bank_id = isset($options['bank_account_id']) ? $options['bank_account_id'] : '';
        
        if ( empty($bank_id) ) {
            $this->log( "⚠️ No se registró pago: No se ha configurado un Banco Global." );
            return false;
        }

        $gateway_id = $order->get_payment_method();
        $method_map = isset($options['payment_method_map']) ? $options['payment_method_map'] : array();
        $alegra_method = isset($method_map[$gateway_id]) ? $method_map[$gateway_id] : 'transfer';

        $payload = array(
            'date'          => date('Y-m-d'),
            'bankAccount'   => array( 'id' => $bank_id ),
            'paymentMethod' => $alegra_method,
            'client'        => array( 'id' => $client_id ),
            'invoices'      => array(
                array(
                    'id'     => $invoice_id,
                    'amount' => $order->get_total()
                )
            ),
            'observations'  => 'Pago automático desde WooCommerce para el pedido #' . $order->get_order_number()
        );

        $response = wp_remote_post( 'https://api.alegra.com/api/v1/payments', array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ),
            'body' => wp_json_encode( $payload )
        ));

        if ( is_wp_error( $response ) ) {
            $this->log( "❌ Error al registrar pago: " . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code == 200 || $code == 201 ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $payment_id = isset($data['id']) ? $data['id'] : true;
            $this->add_sync_log( "✅ Recibo de Caja #$payment_id creado en Alegra para Factura #$invoice_id" );
            return $payment_id;
        } else {
            $body = wp_remote_retrieve_body( $response );
            $this->add_sync_log( "❌ Error API Pago (Code $code): " . $body );
            return false;
        }
    }

    /**
     * Procesar Reembolsos Parciales o Totales (v5.0.0)
     */
    public function process_alegra_partial_refund( $order_id, $refund_id ) {
        $order = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;

        $invoice_id = $order->get_meta( '_alegra_invoice_id' );
        if ( empty( $invoice_id ) ) {
            $this->add_sync_log( "⚠️ No se pudo crear Nota de Crédito: Pedido #$order_id no tiene Factura en Alegra." );
            return;
        }

        $options = get_option( $this->option_name );
        $email = isset($options['email']) ? $options['email'] : '';
        $token = isset($options['token']) ? $options['token'] : '';
        if ( empty($email) || empty($token) ) return;
        $auth = base64_encode( $email . ':' . $token );

        // Construir ítems del reembolso
        $items = array();
        foreach ( $refund->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            
            $sku = $product->get_sku();
            if ( empty( $sku ) ) continue;

            $items[] = array(
                'reference' => $sku,
                'price'     => abs($item->get_total() / $item->get_quantity()),
                'quantity'  => abs($item->get_quantity()),
                'taxes'     => array(
                    array( 'id' => isset($options['default_tax_id']) ? $options['default_tax_id'] : 1 )
                )
            );
        }

        // Si no hay ítems (reembolso de solo envío o ajuste manual de precio)
        if ( empty( $items ) && abs($refund->get_total()) > 0 ) {
            $items[] = array(
                'name'      => 'Ajuste de Reembolso',
                'price'     => abs($refund->get_total()),
                'quantity'  => 1
            );
        }

        $reason = $refund->get_reason();
        $obs = 'Reembolso Woocommerce: ' . ( !empty($reason) ? $reason : 'Sin motivo especificado' );
        $cost_center_id = $options['cost_center_id'] ?? '';

        $payload = array(
            'date'    => date('Y-m-d'),
            'client'  => array( 'id' => $order->get_meta( '_alegra_client_id' ) ),
            'invoice' => array( 'id' => $invoice_id ), // Vinculación técnica
            'items'   => $items,
            'observations' => $obs,
            'status'  => 'draft' // Forzar estado borrador/abierto
        );

        if ( ! empty( $cost_center_id ) ) {
            $payload['costCenter'] = array( 'id' => $cost_center_id );
        }

        $this->add_sync_log( "📉 Enviando Nota de Crédito BORRADOR para Pedido #$order_id" );

        $response = wp_remote_post( 'https://api.alegra.com/api/v1/credit-notes', array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ),
            'body' => wp_json_encode( $payload )
        ));

        if ( is_wp_error( $response ) ) {
            $this->add_sync_log( "❌ Error Nota de Crédito: " . $response->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code == 200 || $code == 201 ) {
                $this->add_sync_log( "✅ Nota de Crédito creada con éxito para Pedido #$order_id" );
            } else {
                $this->add_sync_log( "❌ Error API Nota (Code $code): " . wp_remote_retrieve_body( $response ) );
            }
        }
    }

    /**
     * Sincronización Manual 1 a 1 de un Producto
     */
    public function sync_product_to_alegra( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return false;

        $sku = $product->get_sku();
        if ( ! $sku ) return new WP_Error( 'no_sku', 'El producto no tiene SKU' );

        $options = get_option( $this->option_name );
        $auth = base64_encode( $options['email'] . ':' . $options['token'] );

        // 1. Buscar si existe
        $search = wp_remote_get( 'https://api.alegra.com/api/v1/items?reference=' . urlencode($sku), array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( ! is_wp_error( $search ) && wp_remote_retrieve_response_code( $search ) == 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $search ), true );
            if ( ! empty($data) && isset($data[0]['id']) ) {
                return $data[0]['id'];
            }
        }

        // 2. Si no existe, crear
        $payload = array(
            'name'      => $product->get_name(),
            'price'     => array( array( 'price' => $product->get_price(), 'idPriceList' => 1 ) ),
            'reference' => $sku,
            'tax'       => array( array( 'id' => isset($options['default_tax_id']) ? $options['default_tax_id'] : '1' ) ),
            'inventory' => array(
                'unit' => 'unit',
                'type' => 'simple'
            )
        );

        $response = wp_remote_post( 'https://api.alegra.com/api/v1/items', array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode( $payload )
        ));

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset($body['id']) ? $body['id'] : false;
    }

    public function ajax_sync_single_product() {
        check_ajax_referer( 'alegra_sync_prod', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Acceso no autorizado' );
        $product_id = intval( $_POST['product_id'] );
        $res = $this->sync_product_to_alegra( $product_id );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        } elseif ( $res ) {
            wp_send_json_success( 'Sincronizado correctamente' );
        } else {
            wp_send_json_error( 'Error desconocido al sincronizar' );
        }
    }

    public function add_alegra_product_metabox() {
        add_meta_box( 'alegra_product_sync_box', 'Alegra Pro: Inventario', array( $this, 'render_alegra_product_metabox' ), 'product', 'side', 'default' );
    }

    public function render_alegra_product_metabox( $post ) {
        ?>
        <div style="padding: 10px 0;">
            <button type="button" class="btn-alegra-sync-prod button button-secondary" data-product="<?php echo $post->ID; ?>" style="width: 100%;">
                🔄 Sincronizar 1 a 1 con Alegra
            </button>
            <div class="alegra-prod-msg" style="margin-top: 10px; font-size: 11px;"></div>
        </div>
        <script>
            jQuery(document).ready(function($){
                $('.btn-alegra-sync-prod').click(function(){
                    var btn = $(this);
                    var msg = btn.next('.alegra-prod-msg');
                    btn.prop('disabled', true).text('⏳ Sincronizando...');
                    $.post(ajaxurl, {
                        action: 'alegra_sync_single_product',
                        product_id: btn.data('product'),
                        _ajax_nonce: '<?php echo wp_create_nonce("alegra_sync_prod"); ?>'
                    }, function(res){
                        if(res.success) {
                            msg.html('<span style="color: green;">✅ ' + res.data + '</span>');
                        } else {
                            msg.html('<span style="color: red;">❌ ' + res.data + '</span>');
                        }
                        btn.prop('disabled', false).text('🔄 Re-sincronizar');
                    });
                });
            });
        </script>
        <?php
    }

    public function add_product_list_sync_button( $actions, $post ) {
        if ( $post->post_type !== 'product' ) return $actions;
        
        $actions['alegra_sync'] = '<a href="#" class="btn-alegra-sync-prod" data-product="' . $post->ID . '" style="color: #3182ce; font-weight: bold;">Sync Alegra</a>';
        return $actions;
    }

    public function add_product_list_js() {
        if ( ! is_admin() || get_post_type() !== 'product' ) return;
        ?>
        <script>
            jQuery(document).ready(function($){
                $(document).on('click', '.btn-alegra-sync-prod', function(e){
                    if($(this).closest('.alegra-section').length || $(this).closest('#alegra_product_sync_box').length) return; 
                    e.preventDefault();
                    var btn = $(this);
                    var oldText = btn.text();
                    btn.text('⏳...').css('opacity', '0.5');
                    
                    $.post(ajaxurl, {
                        action: 'alegra_sync_single_product',
                        product_id: btn.data('product'),
                        _ajax_nonce: '<?php echo wp_create_nonce("alegra_sync_prod"); ?>'
                    }, function(res){
                        if(res.success) {
                            btn.text('✅ Ok').css('color', 'green');
                        } else {
                            alert('Error: ' + res.data);
                            btn.text(oldText).css('opacity', '1');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'alegra_test_conn', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Acceso no autorizado' );

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        if ( empty($email) || empty($token) ) {
            wp_send_json_error( 'Faltan credenciales' );
        }

        $auth = base64_encode( $email . ':' . $token );
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/company', array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Error de red: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset($body['name']) ) {
            wp_send_json_success( array( 'name' => $body['name'] ) );
        } else {
            $msg = isset($body['message']) ? $body['message'] : 'Token inválido';
            wp_send_json_error( $msg );
        }
    }

    private function get_or_create_client( $order, $auth ) {
        $options = get_option( $this->option_name );
        
        // 1. Capturar identificación
        $configured_id_key = isset($options['identification_meta_key']) ? $options['identification_meta_key'] : 'billing_nit';
        $id_keys = array( $configured_id_key, '_'.$configured_id_key, '_billing_cedula', '_billing_nit', '_billing_dni', '_billing_documento', 'billing_cedula', 'billing_nit' );
        $identification = '';
        foreach ( $id_keys as $key ) {
            $val = $order->get_meta( $key, true );
            if ( ! empty( $val ) ) {
                $identification = $val;
                break;
            }
        }
        
        if ( empty($identification) ) {
            $identification = '222222222222';
        }

        // 2. Detectar Tipo de Documento (Alegra Approved List)
        $allowed_types = array( 'NIT', 'CC', 'DIE', 'PP', 'CE', 'TE', 'TI', 'RC', 'FOREIGN_NIT', 'NUIP' );
        $custom_key = isset($options['tipo_documento_meta_key']) ? $options['tipo_documento_meta_key'] : 'billing_tipo_documento';
        
        $detected_type = '';
        foreach ( array( $custom_key, '_'.$custom_key, '_billing_tipo_documento', '_billing_document_type', 'billing_document_type' ) as $key ) {
            $val = $order->get_meta( $key, true );
            if ( ! empty($val) && in_array( strtoupper($val), $allowed_types ) ) {
                $detected_type = strtoupper($val);
                break;
            }
        }

        // Respaldo inteligente si no se detectó tipo
        $is_company = ! empty( $order->get_billing_company() );
        if ( empty($detected_type) ) {
            $detected_type = $is_company ? 'NIT' : 'CC';
        }

        $id_obj = array(
            'type'   => $detected_type,
            'number' => $identification
        );

        // Lógica de DV (Solo si es NIT)
        if ( $detected_type === 'NIT' ) {
            $parts = explode( '-', $identification );
            if ( count($parts) > 1 ) {
                $id_obj['number'] = preg_replace('/\D/', '', $parts[0]);
                $id_obj['dv']     = preg_replace('/\D/', '', $parts[1]);
            } else {
                $id_obj['number'] = preg_replace('/\D/', '', $identification);
                $id_obj['dv']     = $this->calculate_nit_dv( $identification );
            }
        }

        // 3. Buscar Cliente Existente
        $search = wp_remote_get( 'https://api.alegra.com/api/v1/contacts?identification=' . urlencode($id_obj['number']), array(
            'headers' => array( 'Authorization' => 'Basic ' . $auth )
        ));

        if ( ! is_wp_error( $search ) && wp_remote_retrieve_response_code( $search ) == 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $search ), true );
            if ( ! empty($data) && isset($data[0]['id']) ) {
                return $data[0]['id'];
            }
        }

        // 4. Preparar Datos para Creación
        $geo = $this->normalize_location( $order->get_billing_state(), $order->get_billing_city() );
        $fiscal_responsibility = isset($options['default_fiscal_responsibility']) ? $options['default_fiscal_responsibility'] : 'R-99-PN';

        // 5. Crear Cliente
        $client_payload = array(
            'name'           => $is_company ? $order->get_billing_company() : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'identificationObject' => $id_obj,
            'email'          => $order->get_billing_email(),
            'phonePrimary'   => $order->get_billing_phone(),
            'address'        => array(
                'address'    => $order->get_billing_address_1(),
                'city'       => $geo['city'],
                'department' => $geo['department']
            ),
            'type'           => array( 'client' ),
            'fiscalResponsibilities' => array( array( 'code' => $fiscal_responsibility ) )
        );

        $response = wp_remote_post( 'https://api.alegra.com/api/v1/contacts', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ),
            'body'    => wp_json_encode( $client_payload )
        ));

        if ( ! is_wp_error( $response ) ) {
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code == 200 || $http_code == 201 ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                return $body['id'];
            }
        }

        return false;
    }

    /**
     * Lógica de Mapeo de Impuestos
     */
    private function get_matching_tax_id( $item, $auth, $default_id ) {
        $taxes = $item->get_taxes();
        if ( empty($taxes['subtotal']) ) return $default_id;

        $tax_rate_id = key($taxes['subtotal']);
        $tax_rate = WC_Tax::get_rate_percent_value( $tax_rate_id );

        // Si es 0% (libros), devolvemos el ID por defecto o buscamos uno de 0%
        if ( $tax_rate == 0 ) return $default_id;

        // Aquí se podría implementar una búsqueda dinámica en /taxes, 
        // pero por simplicidad inicial, si no es 0, intentamos buscarlo 
        // o usamos el default. Para dropshipping avanzado se recomienda 
        // mapear tax classes a IDs de Alegra.
        return $default_id;
    }
}

// Inicializar el Plugin
Alegra_Woo_Pro::get_instance();
