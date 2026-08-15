# GOVP for PrestaShop

Módulo autoasistido para PrestaShop 8–9. Genera un GOVP al alcanzar el estado
configurado, conserva el enlace en el pedido y reintenta fallos mediante una
cola idempotente.

## Instalación

1. Comprima la carpeta `govpexchange` y súbala desde el gestor de módulos.
2. Abra **Configurar**.
3. Cree una conexión PrestaShop en GOVP Exchange y pegue el token.
4. Elija estado, vigencia y visibilidad para el cliente.

La página de configuración muestra una URL cron protegida. La cola también
procesa un trabajo durante tráfico normal y puede ejecutarse manualmente.

El servicio externo, sus términos y privacidad se encuentran en:

- https://gemacode.org/govp-exchange
- https://partners.gemacode.org/legal/terminos
- https://partners.gemacode.org/legal/privacidad

## Desarrollo y prueba nativa

El repositorio incluye un entorno desechable basado en las imágenes oficiales de
PrestaShop y MariaDB. Docker es el único requisito para la prueba de integración.

```bash
npm run lint
npm run test:native
```

La prueba inicia una tienda real, monta el módulo en `/modules/govpexchange`, lo
instala y desinstala mediante la consola de PrestaShop y destruye todos los datos.
Puede seleccionar otra imagen compatible:

```bash
PRESTASHOP_IMAGE=prestashop/prestashop:8 npm run test:native
PRESTASHOP_IMAGE=prestashop/prestashop:9 npm run test:native
```

Para crear el ZIP instalable: `npm run package`. El proyecto se distribuye bajo
AFL-3.0; consulte `CONTRIBUTING.md` y `SECURITY.md`.

