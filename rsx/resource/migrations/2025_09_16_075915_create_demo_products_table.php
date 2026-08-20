<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement() with raw SQL
     * ❌ Schema::create() with Blueprint
     * 
     * REQUIRED: ALL tables MUST have: id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
     * No exceptions - every table needs this exact ID column (SIGNED for easier migrations)
     * 
     * Integer types: Use BIGINT for all integers, TINYINT(1) for booleans only
     * Never use unsigned - all integers should be signed
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Create demo_products table for Ajax ORM demonstration
        DB::statement("
            CREATE TABLE demo_products (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(10,2) NOT NULL,
                status_id INT NOT NULL DEFAULT 1,
                category_id INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                INDEX idx_status_id (status_id),
                INDEX idx_category_id (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert demo data
        DB::statement("
            INSERT INTO demo_products (name, description, price, status_id, category_id) VALUES
            ('Wireless Mouse', 'Ergonomic wireless mouse with 2.4GHz connectivity', 29.99, 1, 1),
            ('Vintage T-Shirt', 'Classic cotton t-shirt with retro design', 19.99, 1, 2),
            ('JavaScript: The Good Parts', 'Essential guide to JavaScript programming', 39.99, 2, 3),
            ('Premium Coffee Beans', 'Single-origin Arabica beans from Colombia', 24.99, 1, 4),
            ('Gaming Laptop', 'High-performance laptop with RTX graphics', 1299.99, 3, 1)
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
