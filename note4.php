状态变量是永久地存储在合约存储中的值，即合约 contract 的属性 === 类 Class 的属性
函数是代码的可执行单元。函数通常在合约内部定义，但也可以在合约外定义。
事件是能方便地调用以太坊虚拟机日志功能的接口。
Solidity 是一种静态类型语言，
这意味着每个变量（状态变量和局部变量）都需要在编译时指定变量的类型。
地址类型 Address
地址类型有两种形式，他们大致相同：

地址类型
address：保存一个20字节的值（以太坊地址的大小）。
address payable ：可支付地址，与 address 相同，不过有成员函数 transfer 和 send 。
这种区别背后的思想是 address payable 可以接受以太币的地址，
而一个普通的 address 则不能。
允许从 address payable 到 address 的隐式转换，
而从 address 到 address payable 必须显示的转换, 通过 payable(<address>) 进行转换。

地址类型成员变量
balance 和 transfer
可以使用 balance 属性来查询一个地址的余额， 
也可以使用 transfer 函数向一个可支付地址（payable address）发送 以太币Ether （以 wei 为单位）
send
send 是 transfer 的低级版本。如果执行失败，当前的合约不会因为异常而终止，但 send 会返回 false。

在合约外部声明结构体可以使其被多个合约共享


