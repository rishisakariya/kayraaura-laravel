# Kayra Aura - Jewellery E-commerce API

## Project Overview
Kayra Aura is a jewellery e-commerce platform built with Laravel API-only architecture. The system provides separate APIs for admin management and frontend customer interactions.

## Architecture
- **Backend**: Laravel 13 (API Only)
- **Database**: MySQL/PostgreSQL
- **Authentication**: Laravel Sanctum
- **Payment Gateway**: Razorpay with Webhooks
- **File Storage**: Local/Cloud (AWS S3 recommended)
- **API Documentation**: Laravel API Resources

## Project Structure
```
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   ├── Frontend/
│   │   │   └── Auth/
│   │   ├── Middleware/
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Models/
│   └── Services/
├── database/
│   ├── migrations/
│   └── seeders/
└── routes/
    ├── api.php
    └── admin.php
```

## API Endpoints

### Admin API Endpoints

#### Authentication
```
POST   /cpanel/login
POST   /cpanel/logout
GET    /cpanel/profile
PUT    /cpanel/profile
POST   /cpanel/refresh-token
```

#### Categories Management
```
GET    /cpanel/categories
POST   /cpanel/categories
GET    /cpanel/categories/{id}
PUT    /cpanel/categories/{id}
DELETE /cpanel/categories/{id}
```

#### Products Management
```
GET    /cpanel/products
POST   /cpanel/products with images upload
GET    /cpanel/products/{id}
PUT    /cpanel/products/{id} not craeted put method pass direct edit_value
DELETE /cpanel/products/{id}
```

#### Users Management
```
GET    /cpanel/users
GET    /cpanel/users/{id}
PUT    /cpanel/users/{id}
DELETE /cpanel/users/{id}
POST   /cpanel/users/{id}/ban
POST   /cpanel/users/{id}/unban
```

#### Orders Management
```
GET    /cpanel/orders
GET    /cpanel/orders/{id}
PUT    /cpanel/orders/{id}/status
GET    /cpanel/orders/{id}/items
POST   /cpanel/orders/{id}/tracking
```

#### Dashboard Analytics
```
GET    /cpanel/dashboard/stats
GET    /cpanel/dashboard/sales
GET    /cpanel/dashboard/products
GET    /cpanel/dashboard/orders
```

### Frontend API Endpoints

#### Authentication
```
POST   /api/auth/register
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/profile
PUT    /api/auth/profile
POST   /api/auth/forgot-password
POST   /api/auth/reset-password
POST   /api/auth/verify-email
```

#### Products (Public)
```
GET    /api/products
GET    /api/products/{id}
GET    /api/products/featured
GET    /api/products/category/{category_id}
GET    /api/products/search
```

#### Categories (Public)
```
GET    /api/categories
GET    /api/categories/{id}
```

#### Cart Management
```
GET    /api/cart
POST   /api/cart/add
PUT    /api/cart/update/{item_id}
DELETE /api/cart/remove/{item_id}
DELETE /api/cart/clear
```

#### Orders
```
GET    /api/orders
GET    /api/orders/{id}
POST   /api/orders/create
POST   /api/orders/{id}/cancel
```

#### Wishlist
```
GET    /api/wishlist
POST   /api/wishlist/add
DELETE /api/wishlist/remove/{product_id}
```

#### Reviews & Ratings
```
GET    /api/products/{id}/reviews
POST   /api/products/{id}/reviews
PUT    /api/reviews/{id}
DELETE /api/reviews/{id}
```

## Database Schema

### Users Table
```sql
- id (PK)
- name
- email
- password
- phone
- role (admin, customer)
- email_verified_at
- banned_until
- created_at
- updated_at
```

### Categories Table
```sql
- id (PK)
- name
- slug
- description
- image
- parent_id (FK)
- sort_order
- is_active
- created_at
- updated_at
```

### Products Table
```sql
- id (PK)
- name
- slug
- description
- short_description
- sku
- price
- sale_price
- cost_price
- weight
- dimensions
- category_id (FK)
- is_active
- is_featured
- stock_quantity
- track_stock
- created_at
- updated_at
```

### Product Images Table
```sql
- id (PK)
- product_id (FK)
- image_path
- alt_text
- sort_order
- is_primary
```

### Orders Table
```sql
- id (PK)
- user_id (FK)
- order_number
- status (pending, processing, shipped, delivered, cancelled)
- subtotal
- tax_amount
- shipping_amount
- total_amount
- payment_method
- payment_status
- shipping_address
- billing_address
- notes
- created_at
- updated_at
```

### Order Items Table
```sql
- id (PK)
- order_id (FK)
- product_id (FK)
- quantity
- price
- total
```

### Cart Table
```sql
- id (PK)
- user_id (FK)
- product_id (FK)
- quantity
- created_at
- updated_at
```

## Payment Gateway Integration

### Razorpay Configuration
1. **Setup Requirements**
   - Razorpay Account
   - API Keys (Live/Test)
   - Webhook Endpoint

2. **Payment Flow**
   ```
   Frontend → Create Order → Razorpay Payment → Webhook Verification → Order Update
   ```

3. **Webhook Events**
   - `payment.authorized`
   - `payment.failed`
   - `refund.processed`

4. **Implementation Steps**
   - Install Razorpay SDK
   - Configure environment variables
   - Create payment orders
   - Handle webhook callbacks
   - Update order status

### Environment Variables
```env
RAZORPAY_KEY_ID=your_key_id
RAZORPAY_KEY_SECRET=your_key_secret
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret
```

## Future Features

### Delivery Partner Integration (Ecart)
- **Planned Features**
  - Real-time tracking
  - Multiple delivery options
  - Delivery cost calculation
  - Order status synchronization

- **Implementation Timeline**
  - Phase 1: Basic API integration
  - Phase 2: Advanced tracking
  - Phase 3: Multi-partner support

## Security Considerations

### Authentication & Authorization
- Laravel Sanctum for API authentication
- Role-based access control
- Rate limiting on sensitive endpoints
- Input validation and sanitization

### Data Protection
- Password hashing (bcrypt)
- Sensitive data encryption
- GDPR compliance
- Audit logging for admin actions

## API Response Format

### Success Response
```json
{
  "success": true,
  "data": {},
  "message": "Operation successful"
}
```

### Error Response
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Error description",
    "details": {}
  }
}
```

## Development Guidelines

### Code Standards
- Follow PSR-12 coding standards
- Use Laravel best practices
- Implement proper error handling
- Write comprehensive tests

### API Versioning
- Use URL versioning: `/api/v1/`
- Maintain backward compatibility
- Document deprecated endpoints

### Testing Strategy
- Unit tests for business logic
- Feature tests for API endpoints
- Integration tests for payment flows
- Performance testing for scalability

## Deployment

### Environment Setup
- Production environment variables
- Database migrations
- File storage configuration
- SSL certificate setup
- Monitoring and logging

### Performance Optimization
- Database indexing
- Caching strategies (Redis)
- Image optimization
- CDN integration
- API response caching

## Monitoring & Maintenance

### Logging
- Application logs
- Error tracking
- Performance metrics
- User activity logs

### Backup Strategy
- Database backups
- File storage backups
- Configuration backups
- Disaster recovery plan

## Support & Documentation

### API Documentation
- OpenAPI/Swagger specification
- Postman collection
- Interactive API docs
- Code examples

### Developer Resources
- Setup guide
- API reference
- Troubleshooting guide
- Best practices documentation

---

## Next Steps

1. **Immediate Tasks**
   - Set up authentication system
   - Create database migrations
   - Implement basic CRUD operations
   - Set up Razorpay integration

2. **Development Phases**
   - Phase 1: Core API development
   - Phase 2: Admin panel features
   - Phase 3: Frontend integration
   - Phase 4: Payment and shipping
   - Phase 5: Advanced features

3. **Testing & Deployment**
   - Unit and integration testing
   - Staging environment setup
   - Production deployment
   - Performance optimization

This documentation provides a comprehensive roadmap for developing the Kayra Aura jewellery e-commerce platform with all the required features and future scalability considerations.


## Development Requirements & Standards

### 1. Controller Method Implementation
- **No separate PUT/PATCH methods required** - Use `store()` method for both create and update operations
- Implement conditional logic based on `edit_value` parameter:
  - `edit_value = 0`: Create new record
  - `edit_value > 0`: Update existing record
- All operations should use POST method for consistency

**Example Implementation Pattern:**
```php
public function store(AnnouncementStoreRequest $request): JsonResponse
{
    if ((int)$request->input('edit_value') === 0) {
        // Create new record logic
        DB::beginTransaction();
        $announcement = new Announcement;
        $announcement->status = 'active';
        $announcement->save();
        
        // Handle translations
        $languages = CacheHelper::getLanguageCatch('admin_language');
        foreach ($languages as $language) {
            $announcementTranslation = new AnnouncementTranslation;
            $announcementTranslation->title = $request->input($language['language_code'] . '_title');
            $announcementTranslation->announcement_id = $announcement->id;
            $announcementTranslation->locale = $language['language_code'];
            $announcementTranslation->save();
        }
        DB::commit();
        
        $this->auditLogCreate($announcement, 'Announcement created', $request);
        return response()->json(['message' => trans('messages.announcement_added_successfully')]);
    }

    // Update existing record logic
    $announcement = Announcement::find($request->input('edit_value'));
    if ($announcement) {
        $oldValues = $this->getOldValuesBeforeUpdate($announcement);
        $announcement->save();
        
        // Update or create translations
        $languages = CacheHelper::getLanguageCatch('admin_language');
        foreach ($languages as $language) {
            AnnouncementTranslation::updateOrCreate(
                [
                    'announcement_id' => $request->input('edit_value'),
                    'locale' => $language['language_code'],
                ],
                [
                    'title' => $request->input($language['language_code'] . '_title'),
                ]
            );
        }
        DB::commit();
        
        $announcement->refresh();
        $this->auditLogUpdate($announcement, $oldValues, $announcement->getAttributes(), 'Announcement updated', $request);
        return response()->json(['message' => trans('messages.announcement_updated_successfully')]);
    }

    return response()->json(['message' => trans('messages.announcement_not_found')], 404);
}
```

### 2. Request Validation Files
- **Create dedicated Form Request classes** for all operations
- Place in `app/Http/Requests/` directory
- Use proper validation rules and custom messages
- Include authorization checks if needed

**Example Request Class Structure:**
```php
class AnnouncementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Add authorization logic if needed
    }

    public function rules(): array
    {
        return [
            'edit_value' => 'required|integer|min:0',
            'en_title' => 'required|string|max:255',
            'fr_title' => 'nullable|string|max:255',
            // Add other language fields as needed
        ];
    }

    public function messages(): array
    {
        return [
            'en_title.required' => 'English title is required',
            'edit_value.required' => 'Edit value is required',
        ];
    }
}
```

### 3. Resource Controllers with Standard Methods
- **Use Laravel Resource Controllers** for consistent API structure
- Implement standard RESTful methods: `index`, `store`, `show`, `destroy`
- Use API Resources for response formatting
- Keep controllers clean and focused

**Standard Controller Structure:**
```php
class AnnouncementController extends Controller
{
    public function index()
    {
        // List all records with pagination
    }

    public function store(AnnouncementStoreRequest $request): JsonResponse
    {
        // Handle both create and update operations
    }

    public function show($id)
    {
        // Show single record
    }

    public function destroy($id)
    {
        // Delete record
    }
}
```

### 4. API Resources for Response Formatting
- **Create dedicated Resource classes** for consistent API responses
- Transform data into standardized format
- Handle relationships and nested data
- Include metadata when needed

**Example Resource Structure:**
```php
class AnnouncementResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'translations' => AnnouncementTranslationResource::collection($this->whenLoaded('translations')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
```

### 5. API Routes Simplification
- **Use resource routing** for cleaner route definitions
- Single line registration for all standard methods
- Custom routes can be added separately if needed

**Example Route Definition:**
```php
// In routes/api.php
Route::apiResource('announcements', AnnouncementController::class);
// This automatically creates: GET, POST, PUT, DELETE routes
```

### 6. Multilingual Support Pattern
- **Use consistent naming convention** for multilingual fields
- Pattern: `{language_code}_{field_name}` (e.g., `en_title`, `fr_title`)
- Handle translations in controllers using language cache
- Use updateOrCreate for translation management

### 7. Audit Logging Requirements
- **Implement audit trails** for all create/update/delete operations
- Log old values before updates
- Include user context and timestamps
- Use dedicated audit log methods in controllers

### 8. Database Transaction Management
- **Use DB transactions** for multi-table operations
- Ensure data consistency
- Handle rollbacks on errors
- Commit only after all operations succeed