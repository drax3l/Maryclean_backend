<?php

namespace App\Controllers;

use CodeIgniter\Controller;

/**
 * DocsController
 * 
 * Controlador ultra-delgado (Thin Controller) para servir la interfaz 
 * estática de Swagger UI sin acoplar lógica en los demás controladores.
 */
class DocsController extends Controller
{
    public function index()
    {
        // Se renderiza la vista que inyecta Swagger UI vía CDN
        return view('swagger_ui');
    }
}
