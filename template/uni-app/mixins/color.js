import { brandColorStyle } from '@/utils/brand';
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

export default {
  data() {
    return {
      colorStyle: brandColorStyle,
      colorStatus: "",
    };
  },
  created() {
    this.colorStyle = brandColorStyle;
    uni.$on("ok", (data) => {
      this.colorStyle = brandColorStyle;
    });
  },
  methods: {},
};
