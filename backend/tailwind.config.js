import defaultTheme from "tailwindcss/defaultTheme";
import forms from "@tailwindcss/forms";
import scrollbar from "tailwind-scrollbar";

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"DM Sans"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: {
                    DEFAULT: "#242464", // primary-500
                    50: "#f0f3fe",
                    100: "#dee3fb",
                    200: "#c4d0f9",
                    300: "#9cb2f4",
                    400: "#6d89ed",
                    500: "#4b62e6",
                    600: "#3643da",
                    700: "#2d32c8",
                    800: "#2b2ca2",
                    900: "#272a81",
                    950: "#242464",
                },
                secondary: {
                    DEFAULT: "#ec4334",
                    50: "#fef3f2",
                    100: "#fee4e2",
                    200: "#fececa",
                    300: "#fbada6",
                    400: "#f77d72",
                    500: "#ec4334",
                    600: "#db3627",
                    700: "#b82a1d",
                    800: "#98261c",
                    900: "#7e261e",
                    950: "#450f0a",
                },
            },
        },
    },

    plugins: [forms, scrollbar],
};
