#!/bin/bash

# Cub Scout Web Application - Test Runner Script
# This script helps set up and run browser tests

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Cub Scout Web Application - Browser Test Runner${NC}"
echo "=================================================="

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo -e "${RED}Error: Node.js is not installed. Please install Node.js version 16 or higher.${NC}"
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node -v | cut -d'v' -f2)
REQUIRED_VERSION="16.0.0"

if ! printf '%s\n%s\n' "$REQUIRED_VERSION" "$NODE_VERSION" | sort -V -C; then
    echo -e "${RED}Error: Node.js version $NODE_VERSION is too old. Please upgrade to version 16 or higher.${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Node.js version $NODE_VERSION is compatible${NC}"

# Check if we're in the tests directory
if [ ! -f "package.json" ]; then
    echo -e "${RED}Error: Please run this script from the tests directory${NC}"
    exit 1
fi

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo -e "${YELLOW}Installing dependencies...${NC}"
    npm install
fi

# Install Playwright browsers if not already installed
if [ ! -d "node_modules/@playwright/test" ] || [ ! -d "~/.cache/ms-playwright" ]; then
    echo -e "${YELLOW}Installing Playwright browsers...${NC}"
    npx playwright install
fi

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}Warning: .env file not found. Creating from .env.example...${NC}"
    cp .env.example .env
    echo -e "${YELLOW}Please edit .env file with your actual configuration before running tests.${NC}"
    echo ""
    echo "Required configuration:"
    echo "  - BASE_URL: URL where your application is running"
    echo "  - ADMIN_EMAIL: Email of an admin user"
    echo "  - ADMIN_PASSWORD: Password for the admin user"
    echo "  - USER_EMAIL: Email of a regular user"
    echo "  - USER_PASSWORD: Password for the regular user"
    echo ""
    read -p "Press Enter to continue once you've configured .env..."
fi

# Load environment variables
if [ -f ".env" ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

# Check if BASE_URL is accessible
if [ -n "$BASE_URL" ]; then
    echo -e "${YELLOW}Testing connection to $BASE_URL...${NC}"
    if curl -s --head --fail "$BASE_URL" > /dev/null; then
        echo -e "${GREEN}✓ Application is accessible at $BASE_URL${NC}"
    else
        echo -e "${RED}Warning: Cannot connect to $BASE_URL${NC}"
        echo "Please ensure your Cub Scout application is running."
        read -p "Continue anyway? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
fi

# Menu for test options
echo ""
echo "Test Options:"
echo "1. Run all tests"
echo "2. Run tests with browser visible (headed mode)"
echo "3. Run homepage tests only"
echo "4. Run tests in debug mode"
echo "5. Run tests in UI mode (interactive)"
echo "6. View test report"
echo "7. Exit"

read -p "Choose an option (1-7): " choice

case $choice in
    1)
        echo -e "${GREEN}Running all tests...${NC}"
        npm test
        ;;
    2)
        echo -e "${GREEN}Running tests in headed mode...${NC}"
        npm run test:headed
        ;;
    3)
        echo -e "${GREEN}Running homepage tests...${NC}"
        npx playwright test specs/homepage.spec.js
        ;;
    4)
        echo -e "${GREEN}Running tests in debug mode...${NC}"
        npm run test:debug
        ;;
    5)
        echo -e "${GREEN}Starting UI mode...${NC}"
        npm run test:ui
        ;;
    6)
        echo -e "${GREEN}Opening test report...${NC}"
        npm run test:report
        ;;
    7)
        echo "Goodbye!"
        exit 0
        ;;
    *)
        echo -e "${RED}Invalid option. Please choose 1-7.${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}Test execution completed!${NC}"

# Check if test results exist and offer to open report
if [ -d "test-results" ] || [ -d "playwright-report" ]; then
    read -p "Would you like to view the test report? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        npm run test:report
    fi
fi
