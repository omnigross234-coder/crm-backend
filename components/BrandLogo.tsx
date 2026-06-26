type BrandLogoProps = {
  compact?: boolean;
  className?: string;
};

export default function BrandLogo({ compact = false, className = "" }: BrandLogoProps) {
  return (
    <div className={`flex items-center gap-2.5 ${className}`}>
      <img
        src="/crm_web/OmniGrosslogo2.png"
        alt="Omnigross"
        className={compact ? "h-9 w-auto flex-shrink-0" : "h-10 w-auto flex-shrink-0"}
      />
      <span className="text-lg font-bold tracking-tight text-blue-700 whitespace-nowrap">
        Omnigross CRM
      </span>
    </div>
  );
}
