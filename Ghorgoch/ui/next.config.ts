import type { NextConfig } from "next";
import { networkInterfaces } from "node:os";

const nextConfig: NextConfig = {
  output: "standalone",
  allowedDevOrigins: [
    "127.0.0.1",
    ...Object.values(networkInterfaces()).flatMap((addresses) =>
      addresses?.filter((address) => address.family === "IPv4" && !address.internal).map((address) => address.address) ?? [],
    ),
  ],
  // Keep React strict checks enabled while the storefront is developed.
  reactStrictMode: true,
  // Project instructions already live in the repository root.
  agentRules: false,
  async rewrites() {
    const backend = (process.env.LARAVEL_API_URL || process.env.API_URL || "http://localhost:8000").replace(/\/$/, "").replace(/\/api$/, "");
    return [{ source: "/api/:path*", destination: `${backend}/api/:path*` }];
  },
};

export default nextConfig;
