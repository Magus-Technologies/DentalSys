# Comandos del Proyecto (Uso de PHP Herd)

Debido a la incompatibilidad con el PHP global (XAMPP 8.0), usar siempre la ruta de Herd:

- **Instalar dependencias:**
  ```bash
  "/c/Users/User/.config/herd/bin/php82/php.exe" "/c/Users/User/.config/herd/bin/composer.phar" install
  ```

- **Comandos de Artisan:**
  ```bash
  "/c/Users/User/.config/herd/bin/php82/php.exe" artisan [comando]
  ```

- **Verificar versión:**
  ```bash
  "/c/Users/User/.config/herd/bin/php82/php.exe" -v
  ```

Con eso guardado en el proyecto, ya no tendrás que volver a buscar la ruta.