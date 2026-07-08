import type { Metadata } from "next";
import { DM_Sans, Sora } from "next/font/google";
import "./globals.css";

const dmSans = DM_Sans({
  variable: "--font-dm-sans",
  subsets: ["latin"],
  display: "swap",
});

const sora = Sora({
  variable: "--font-sora",
  subsets: ["latin"],
  display: "swap",
});

export const metadata: Metadata = {
  title: {
    default: "Bethany House — Curated homeware, fabric & lifestyle",
    template: "%s · Bethany House",
  },
  description:
    "Bethany House brings you thoughtfully curated homeware, fabric and lifestyle pieces — crafted in Kenya, delivered across the country. Shop online or visit our outlets.",
  keywords: [
    "Bethany House",
    "homeware Kenya",
    "fabric",
    "lifestyle store",
    "Nairobi shopping",
  ],
  openGraph: {
    title: "Bethany House",
    description:
      "Thoughtfully curated homeware, fabric and lifestyle pieces — crafted in Kenya.",
    type: "website",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body className={`${dmSans.variable} ${sora.variable} antialiased`}>
        {children}
      </body>
    </html>
  );
}
