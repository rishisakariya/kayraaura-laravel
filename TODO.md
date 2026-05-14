# TODO

- [x] Step 1: Update migration `create_product_sizes_table` with product_id, size_text, quantity, price + FK/unique constraints
- [x] Step 2: Update `app/Models/ProductSize.php` (fillable, casts, product relationship)
- [x] Step 3: Update `app/Models/Product.php` with sizes relationship
- [x] Step 4: Update `app/Http/Requests/Admin/ProductStoreRequest.php` to validate `sizes` payload (required on create)
- [x] Step 5: Update `app/Http/Controllers/Admin/ProductController.php` to persist sizes on create/update

- [x] Step 6: Update API response `app/Http/Resources/ProductResource.php` to include sizes

- [x] Step 7: Run `php artisan migrate`
- [x] Step 8: Run `php artisan test`
- [x] Step 9: Fix any compilation/runtime issues found
