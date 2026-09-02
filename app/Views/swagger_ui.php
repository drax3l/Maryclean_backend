<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>MaryClean API - Swagger UI</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.1.0/swagger-ui.css" />
  <style>
    body {
      margin: 0;
      background: #fafafa;
    }
    .swagger-ui .topbar { display: none; } /* Ocultar la barra superior predeterminada */
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  
  <script src="https://unpkg.com/swagger-ui-dist@5.1.0/swagger-ui-bundle.js" crossorigin></script>
  <script src="https://unpkg.com/swagger-ui-dist@5.1.0/swagger-ui-standalone-preset.js" crossorigin></script>
  
  <script>
    window.onload = () => {
      window.ui = SwaggerUIBundle({
        // REGLA 1: URL base dinámica generada por CI4 para evitar problemas de CORS/Rutas
        url: "<?= base_url('docs/swagger.yaml') ?>",
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [
          SwaggerUIBundle.presets.apis,
          SwaggerUIStandalonePreset
        ],
        layout: "BaseLayout",
        // Habilitar persistencia de token JWT en el navegador
        persistAuthorization: true
      });
    };
  </script>
</body>
</html>
