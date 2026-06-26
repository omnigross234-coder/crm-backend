// import type { NextConfig } from "next";

// const nextConfig = {
//   output: 'export',
//   trailingSlash: true,
//   basePath: '/crm_web',
//   images: { unoptimized: true },
// };
// export default nextConfig;
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: process.env.NODE_ENV === 'production' ? 'export' : undefined,
  trailingSlash: true,
  basePath: '/crm_web',
  assetPrefix: process.env.NODE_ENV === 'production' ? '/crm_web/' : undefined,
  images: { unoptimized: true },
};

export default nextConfig;
